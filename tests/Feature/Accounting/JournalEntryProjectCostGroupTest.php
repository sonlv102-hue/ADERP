<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\ProjectWipEntry;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: Nợ 154 DA-0001 cost_group=labor / Có 3341 → post OK, line có project_id, WIP tạo, cost_type=labor.
 * TC2: Nợ 154 không project_id → chặn, báo lỗi "phải chọn Dự án".
 * TC3: Nợ 154 có project_id không cost_group → chặn, báo lỗi "chọn Nhóm chi phí".
 * TC4: Nợ 154 DA-0001 + Nợ 154 DA-0002 / Có 3341 → 2 WIP đúng từng dự án.
 * TC5: Nợ 154 DA-0001 + Nợ 1331 / Có 3312 → WIP chỉ lấy phần Nợ 154, không tính VAT.
 * TC6: Đảo bút toán đã post → WIP bị reversed, không hard-delete.
 * TC7: Retry post không tạo trùng WIP.
 * TC8: journal-entries:audit-project-dimensions phát hiện JE thiếu project_id/cost_group/WIP.
 */
class JournalEntryProjectCostGroupTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;
    private User $user;
    private Project $projectA;
    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounting = app(AccountingService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'je-cost-group@test.local'],
            ['name' => 'JE Cost Group Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->seedAccount('154', 'asset', 'debit');
        $this->seedAccount('3341', 'liability', 'credit');
        $this->seedAccount('1331', 'asset', 'debit');
        $this->seedAccount('3312', 'liability', 'credit');

        $customer = Customer::create(['code' => 'KH-JECG', 'name' => 'KH JE Cost Group', 'is_active' => true]);
        $this->projectA = Project::create([
            'code' => 'DA-JECG-A', 'name' => 'Dự án A', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $this->user->id,
        ]);
        $this->projectB = Project::create([
            'code' => 'DA-JECG-B', 'name' => 'Dự án B', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $this->user->id,
        ]);
    }

    private function seedAccount(string $code, string $type, string $normalBalance): AccountCode
    {
        return AccountCode::firstOrCreate(['code' => $code], [
            'name' => 'TK ' . $code, 'type' => $type, 'normal_balance' => $normalBalance,
            'level' => 3, 'is_detail' => true, 'is_active' => true,
        ]);
    }

    // ── TC1 ─────────────────────────────────────────────────────────────────

    public function test_tc1_post_with_project_and_cost_group_creates_wip(): void
    {
        $entry = $this->accounting->post(
            description: 'Kết chuyển lương kỹ thuật',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 10_000_000, 'credit' => 0, 'project_id' => $this->projectA->id, 'cost_group' => 'labor'],
                ['account' => '3341', 'debit' => 0, 'credit' => 10_000_000],
            ],
        );

        $this->assertEquals('posted', $entry->status);

        $line = $entry->lines()->where('account_code', '154')->first();
        $this->assertEquals($this->projectA->id, $line->project_id);
        $this->assertEquals('labor', $line->cost_group);

        $wip = ProjectWipEntry::where('journal_entry_line_id', $line->id)->first();
        $this->assertNotNull($wip, 'Phải tạo project_wip_entries cho dòng Nợ 154.');
        $this->assertEquals($this->projectA->id, $wip->project_id);
        $this->assertEquals('labor', $wip->cost_type);
        $this->assertEquals(10_000_000, (float) $wip->amount);
        $this->assertEquals('active', $wip->status);
        $this->assertEquals('manual_journal_entry', $wip->source_type);
    }

    // ── TC2 ─────────────────────────────────────────────────────────────────

    public function test_tc2_post_154_without_project_is_blocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dòng Nợ TK154 phải chọn Dự án.');

        $this->accounting->post(
            description: 'Thiếu dự án',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 5_000_000, 'credit' => 0, 'cost_group' => 'labor'],
                ['account' => '3341', 'debit' => 0, 'credit' => 5_000_000],
            ],
        );
    }

    // ── TC3 ─────────────────────────────────────────────────────────────────

    public function test_tc3_post_154_without_cost_group_is_blocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Dòng Nợ TK154 phải chọn Nhóm chi phí.');

        $this->accounting->post(
            description: 'Thiếu nhóm chi phí',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 5_000_000, 'credit' => 0, 'project_id' => $this->projectA->id],
                ['account' => '3341', 'debit' => 0, 'credit' => 5_000_000],
            ],
        );
    }

    // ── TC4 ─────────────────────────────────────────────────────────────────

    public function test_tc4_multi_project_lines_create_separate_wip_each(): void
    {
        $entry = $this->accounting->post(
            description: 'Kết chuyển đa dự án',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 20_000_000, 'credit' => 0, 'project_id' => $this->projectA->id, 'cost_group' => 'labor'],
                ['account' => '154', 'debit' => 15_000_000, 'credit' => 0, 'project_id' => $this->projectB->id, 'cost_group' => 'labor'],
                ['account' => '3341', 'debit' => 0, 'credit' => 35_000_000],
            ],
        );

        $wips = ProjectWipEntry::where('source_type', 'manual_journal_entry')
            ->where('journal_entry_id', $entry->id)->get();

        $this->assertCount(2, $wips, 'Phải tạo đúng 2 WIP, mỗi dự án 1 dòng.');
        $this->assertEquals(20_000_000, (float) $wips->firstWhere('project_id', $this->projectA->id)->amount);
        $this->assertEquals(15_000_000, (float) $wips->firstWhere('project_id', $this->projectB->id)->amount);
    }

    // ── TC5 ─────────────────────────────────────────────────────────────────

    public function test_tc5_vat_line_not_included_in_wip(): void
    {
        $entry = $this->accounting->post(
            description: 'Có VAT',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 10_000_000, 'credit' => 0, 'project_id' => $this->projectA->id, 'cost_group' => 'material'],
                ['account' => '1331', 'debit' => 1_000_000, 'credit' => 0],
                ['account' => '3312', 'debit' => 0, 'credit' => 11_000_000],
            ],
        );

        $wips = ProjectWipEntry::where('journal_entry_id', $entry->id)->get();
        $this->assertCount(1, $wips, 'Chỉ tạo WIP cho dòng Nợ 154, không tính dòng VAT 1331.');
        $this->assertEquals(10_000_000, (float) $wips->first()->amount, 'WIP amount chỉ lấy phần Nợ 154, không cộng VAT.');
    }

    // ── TC6 ─────────────────────────────────────────────────────────────────

    public function test_tc6_reverse_cancels_wip_without_hard_delete(): void
    {
        $entry = $this->accounting->post(
            description: 'Sẽ bị đảo',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 8_000_000, 'credit' => 0, 'project_id' => $this->projectA->id, 'cost_group' => 'labor'],
                ['account' => '3341', 'debit' => 0, 'credit' => 8_000_000],
            ],
        );

        $wip = ProjectWipEntry::where('journal_entry_id', $entry->id)->first();
        $this->assertEquals('active', $wip->status);

        $this->accounting->reverse($entry, 'Test đảo bút toán');

        $wip->refresh();
        $this->assertEquals('reversed', $wip->status, 'WIP phải chuyển sang reversed, không bị xóa.');
        $this->assertDatabaseHas('project_wip_entries', ['id' => $wip->id]);
    }

    // ── TC7 ─────────────────────────────────────────────────────────────────

    public function test_tc7_retry_post_does_not_duplicate_wip(): void
    {
        $entry = $this->accounting->post(
            description: 'Retry test',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 6_000_000, 'credit' => 0, 'project_id' => $this->projectA->id, 'cost_group' => 'labor'],
                ['account' => '3341', 'debit' => 0, 'credit' => 6_000_000],
            ],
        );

        // Gọi lại đúng logic tạo WIP (mô phỏng retry/post lại) — không được tạo thêm bản ghi.
        $this->accounting->createWipForManualEntry($entry->fresh());
        $this->accounting->createWipForManualEntry($entry->fresh());

        $count = ProjectWipEntry::where('journal_entry_id', $entry->id)->count();
        $this->assertEquals(1, $count, 'Retry không được tạo trùng WIP.');
    }

    // ── TC8 ─────────────────────────────────────────────────────────────────

    public function test_tc8_audit_command_detects_missing_dimensions(): void
    {
        // Tạo trực tiếp JE thiếu project_id/cost_group (mô phỏng JE cũ trước khi có validate, giống 999-1004)
        $entry = JournalEntry::create([
            'code' => 'BT-TESTAUDIT', 'entry_date' => '2026-06-15', 'description' => 'JE cũ thiếu dự án',
            'status' => 'posted', 'is_auto' => false, 'created_by' => $this->user->id,
            'fiscal_period' => '2026-06', 'posted_at' => now(),
        ]);
        $entry->lines()->create(['account_code' => '154', 'debit' => 1_000_000, 'credit' => 0, 'sort_order' => 0]);
        $entry->lines()->create(['account_code' => '3341', 'debit' => 0, 'credit' => 1_000_000, 'sort_order' => 1]);

        $this->artisan('journal-entries:audit-project-dimensions')
            ->assertExitCode(1);
    }

    // ── TC9 (bổ sung sau code review): đảo bút toán có dòng Có 154 mang project/cost_group ────
    // Dòng Có 154 không bị validateProjectDimensions bắt buộc (chỉ áp dụng debit>0), nhưng khi đảo,
    // Nợ/Có bị hoán đổi nên dòng này biến thành Nợ 154 — không được validate lại / tạo WIP mới cho
    // chính bút toán đảo (chỉ được cancel WIP của bút toán gốc).

    public function test_tc9_reverse_with_credit_154_project_line_does_not_throw_or_duplicate_wip(): void
    {
        $entry = $this->accounting->post(
            description: 'Kết chuyển giá vốn công trình',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '3341', 'debit' => 9_000_000, 'credit' => 0],
                ['account' => '154', 'debit' => 0, 'credit' => 9_000_000, 'project_id' => $this->projectA->id, 'cost_group' => 'labor'],
            ],
        );

        // Không throw — trước fix, dòng Có154→Nợ154 sau khi đảo bị validateProjectDimensions chặn nhầm.
        $reversal = $this->accounting->reverse($entry, 'Test đảo dòng Có 154');

        $this->assertEquals('posted', $reversal->status);

        // Bút toán đảo không được tự tạo WIP mới (WIP chỉ gắn với bút toán gốc, không phải bút toán đảo).
        $reversalWipCount = ProjectWipEntry::where('journal_entry_id', $reversal->id)->count();
        $this->assertEquals(0, $reversalWipCount, 'Bút toán đảo không được tự tạo WIP mới.');
    }

    public function test_tc9b_bulk_approve_does_not_crash_on_invalid_argument_exception(): void
    {
        $draft = $this->accounting->createDraft(
            description: 'Draft thiếu dự án',
            date: Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '154', 'debit' => 2_000_000, 'credit' => 0],
                ['account' => '3341', 'debit' => 0, 'credit' => 2_000_000],
            ],
        );

        $response = $this->post(route('accounting.journal-entries.bulk-approve'));

        $response->assertSessionHas('success');
        $this->assertEquals('draft', $draft->fresh()->status, 'Draft thiếu dự án phải bị từ chối duyệt, không crash.');
    }
}
