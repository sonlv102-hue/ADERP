<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Accounting\AccountBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class JournalEntryVoidTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;
    private AccountBalanceService $balanceSvc;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = app(AccountingService::class);
        $this->balanceSvc = app(AccountBalanceService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);

        // Bypass toàn bộ RBAC — test chỉ kiểm tra business logic, không test permissions
        Gate::before(fn ($user, $ability) => true);

        // Seed period mở
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // Seed tài khoản chi tiết cần thiết
        $this->seedAccount('1111', 'asset', 'debit', true, '111');
        $this->seedAccount('3311', 'liability', 'credit', true, '331');
    }

    private function seedAccount(string $code, string $type, string $normalBalance, bool $isDetail, ?string $parentCode = null): AccountCode
    {
        if ($parentCode) {
            AccountCode::firstOrCreate(['code' => $parentCode], [
                'name' => 'TK ' . $parentCode, 'type' => $type,
                'normal_balance' => $normalBalance, 'parent_code' => null,
                'level' => 3, 'is_detail' => false, 'is_active' => true,
            ]);
        }

        return AccountCode::firstOrCreate(['code' => $code], [
            'name' => 'TK ' . $code, 'type' => $type,
            'normal_balance' => $normalBalance, 'parent_code' => $parentCode,
            'level' => $parentCode ? 4 : 3, 'is_detail' => $isDetail, 'is_active' => true,
        ]);
    }

    private function makeLines(int $amount = 1_000_000): array
    {
        return [
            ['account' => '1111', 'debit' => $amount, 'credit' => 0],
            ['account' => '3311', 'debit' => 0, 'credit' => $amount],
        ];
    }

    private function postEntry(string $date = '2026-06-10', ?string $description = null): JournalEntry
    {
        return $this->accounting->post(
            description: $description ?? 'Test entry',
            date: Carbon::parse($date),
            lines: $this->makeLines(),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC1: Xóa bút toán nháp thành công
    // ─────────────────────────────────────────────────────────────────────────

    public function test_can_delete_draft_entry(): void
    {
        $entry = JournalEntry::create([
            'code'        => 'BT-TEST001',
            'entry_date'  => '2026-06-10',
            'description' => 'Draft test',
            'status'      => 'draft',
            'is_auto'     => false,
            'created_by'  => $this->user->id,
        ]);

        $response = $this->delete(route('accounting.journal-entries.destroy', $entry));
        $response->assertRedirect(route('accounting.journal-entries.index'));
        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC2: Không hard delete bút toán đã hạch toán
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_hard_delete_posted_entry(): void
    {
        $entry = $this->postEntry();
        $this->assertSame('posted', $entry->status);

        $response = $this->delete(route('accounting.journal-entries.destroy', $entry));
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC3: Hủy bút toán đã hạch toán thành công
    // ─────────────────────────────────────────────────────────────────────────

    public function test_can_void_posted_entry(): void
    {
        $entry = $this->postEntry();

        $response = $this->post(route('accounting.journal-entries.void', $entry), [
            'void_reason' => 'Nhập nhầm',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('voided', $entry->status);
        $this->assertNotNull($entry->voided_at);
        $this->assertSame($this->user->id, $entry->voided_by);
        $this->assertSame('Nhập nhầm', $entry->void_reason);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC4: Bút toán đã hủy không lên trial balance
    // ─────────────────────────────────────────────────────────────────────────

    public function test_voided_entry_excluded_from_balance(): void
    {
        $entry = $this->postEntry('2026-06-10');

        // Trước khi hủy: TK 1111 phải có số dư
        $balanceBefore = $this->balanceSvc->getAllBalancesAsOf('2026-06-30');
        $this->assertArrayHasKey('1111', $balanceBefore);
        $this->assertGreaterThan(0, $balanceBefore['1111']);

        // Hủy
        $this->post(route('accounting.journal-entries.void', $entry));
        $entry->refresh();
        $this->assertSame('voided', $entry->status);

        // Sau khi hủy: TK 1111 không còn số dư
        $balanceAfter = $this->balanceSvc->getAllBalancesAsOf('2026-06-30');
        $this->assertArrayNotHasKey('1111', $balanceAfter);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC5: Hủy cặp từ bút toán gốc (reversed)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_void_pair_from_original_reversed_entry(): void
    {
        $original = $this->postEntry();
        $reversal = $this->accounting->reverse($original, 'Sai nghiệp vụ');

        $original->refresh();
        $this->assertSame('reversed', $original->status);
        $this->assertSame('posted', $reversal->status);

        $response = $this->post(route('accounting.journal-entries.void', $original), [
            'void_reason' => 'Dọn dẹp sổ sách',
        ]);

        $response->assertRedirect(route('accounting.journal-entries.index'));
        $response->assertSessionHasNoErrors();

        $original->refresh();
        $reversal->refresh();

        $this->assertSame('voided', $original->status);
        $this->assertSame('voided', $reversal->status);
        $this->assertNotNull($original->voided_at);
        $this->assertNotNull($reversal->voided_at);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC6: Sau khi hủy cặp, trial balance không lệch
    // ─────────────────────────────────────────────────────────────────────────

    public function test_trial_balance_unaffected_after_void_pair(): void
    {
        $original = $this->postEntry('2026-06-10');
        $this->accounting->reverse($original, 'Đảo thử');
        $original->refresh();

        // Sau khi đảo: original = 'reversed' (bị exclude khỏi balance).
        // Chỉ còn reversal entry (posted) với Cr 1111 = 1M.
        // TK 1111 normal_balance=debit: balance = dr - cr = 0 - 1M = -1M
        $balanceBefore = $this->balanceSvc->getAllBalancesAsOf('2026-06-30');
        $this->assertEquals(-1_000_000, $balanceBefore['1111'] ?? 0);

        $this->post(route('accounting.journal-entries.void', $original));

        // Sau khi hủy cả cặp: cả 2 đều voided → không còn ảnh hưởng balance
        $balanceAfter = $this->balanceSvc->getAllBalancesAsOf('2026-06-30');
        $this->assertArrayNotHasKey('1111', $balanceAfter);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC7: Không cho hủy bút toán thuộc kỳ đã khóa sổ
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_void_entry_in_locked_period(): void
    {
        // Tạo kỳ tháng 5 mở, post entry, rồi khóa kỳ
        AccountingPeriod::create(['year' => 2026, 'month' => 5, 'status' => 'open']);
        $entry = $this->postEntry('2026-05-15');
        AccountingPeriod::where('year', 2026)->where('month', 5)->update(['status' => 'locked']);

        $response = $this->post(route('accounting.journal-entries.void', $entry));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $entry->refresh();
        $this->assertSame('posted', $entry->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC8: UI — không cho hủy bút toán đã hủy
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_void_already_voided_entry(): void
    {
        $entry = $this->postEntry();
        $this->post(route('accounting.journal-entries.void', $entry));

        $entry->refresh();
        $this->assertSame('voided', $entry->status);

        // Thử hủy lần 2
        $response = $this->post(route('accounting.journal-entries.void', $entry));
        $response->assertSessionHas('error');
        $this->assertSame('voided', $entry->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC9: Không hủy bút toán đảo nếu không có bút toán ngược đi kèm
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cannot_void_reversed_entry_without_pair(): void
    {
        $entry = $this->postEntry();
        // Giả lập trạng thái reversed nhưng reversed_by_id = null
        $entry->update(['status' => 'reversed', 'reversed_by_id' => null]);

        $response = $this->post(route('accounting.journal-entries.void', $entry));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $entry->refresh();
        $this->assertSame('reversed', $entry->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC10: Bút toán đã hủy vẫn xem được trong lịch sử
    // ─────────────────────────────────────────────────────────────────────────

    public function test_voided_entry_still_visible_in_history(): void
    {
        $entry = $this->postEntry();
        $this->post(route('accounting.journal-entries.void', $entry));

        // Vẫn tồn tại trong DB
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'status' => 'voided']);

        // Xem được qua API index (không bị filter ra)
        $response = $this->get(route('accounting.journal-entries.index', ['status' => 'voided']));
        $response->assertOk();
    }
}
