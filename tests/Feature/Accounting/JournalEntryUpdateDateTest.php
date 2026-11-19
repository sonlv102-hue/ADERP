<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Sửa ngày hạch toán Phiếu kế toán qua JournalEntryController::update().
 * TC1: draft → đổi ngày OK, fiscal_period đồng bộ.
 * TC2: posted → chặn đổi ngày.
 * TC3: kỳ mới bị khóa → chặn.
 * TC4: kỳ cũ bị khóa → chặn.
 * TC5: gửi lại cùng ngày → không lỗi.
 */
class JournalEntryUpdateDateTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(AccountingService::class);

        $user = User::firstOrCreate(
            ['email' => 'je-date@test.local'],
            ['name' => 'JE Date Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn () => true);

        foreach (['6421' => ['expense', 'debit'], '1111' => ['asset', 'debit']] as $code => [$type, $nb]) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => 'TK ' . $code, 'type' => $type, 'normal_balance' => $nb,
                'level' => 3, 'is_detail' => true, 'is_active' => true,
            ]);
        }
    }

    private function lines(): array
    {
        return [
            ['account' => '6421', 'debit' => 1_000_000, 'credit' => 0],
            ['account' => '1111', 'debit' => 0, 'credit' => 1_000_000],
        ];
    }

    private function updatePayload(string $date): array
    {
        return ['entry_date' => $date, 'description' => 'Chi phí văn phòng'];
    }

    public function test_tc1_draft_entry_date_is_updated(): void
    {
        $entry = $this->accounting->createDraft('Chi phí văn phòng', Carbon::parse('2026-06-15'), $this->lines());

        $this->put(route('accounting.journal-entries.update', $entry), $this->updatePayload('2026-07-03'))
            ->assertRedirect(route('accounting.journal-entries.show', $entry))
            ->assertSessionHas('success');

        $entry->refresh();
        $this->assertSame('2026-07-03', $entry->entry_date->format('Y-m-d'));
        $this->assertSame('2026-07', $entry->fiscal_period);
    }

    public function test_tc2_posted_entry_date_cannot_be_changed(): void
    {
        $entry = $this->accounting->post('Chi phí văn phòng', Carbon::parse('2026-06-15'), $this->lines());

        $this->put(route('accounting.journal-entries.update', $entry), $this->updatePayload('2026-07-03'))
            ->assertSessionHas('error');

        $this->assertSame('2026-06-15', $entry->fresh()->entry_date->format('Y-m-d'));
    }

    public function test_tc3_locked_target_period_blocks_change(): void
    {
        AccountingPeriod::create(['year' => 2026, 'month' => 5, 'status' => 'locked']);
        $entry = $this->accounting->createDraft('Chi phí văn phòng', Carbon::parse('2026-06-15'), $this->lines());

        $this->put(route('accounting.journal-entries.update', $entry), $this->updatePayload('2026-05-20'))
            ->assertSessionHas('error');

        $this->assertSame('2026-06-15', $entry->fresh()->entry_date->format('Y-m-d'));
    }

    public function test_tc4_locked_source_period_blocks_change(): void
    {
        $entry = $this->accounting->createDraft('Chi phí văn phòng', Carbon::parse('2026-06-15'), $this->lines());
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'locked']);

        $this->put(route('accounting.journal-entries.update', $entry), $this->updatePayload('2026-07-03'))
            ->assertSessionHas('error');

        $this->assertSame('2026-06-15', $entry->fresh()->entry_date->format('Y-m-d'));
    }

    public function test_tc5_same_date_is_noop(): void
    {
        $entry = $this->accounting->createDraft('Chi phí văn phòng', Carbon::parse('2026-06-15'), $this->lines());
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'locked']);

        $this->put(route('accounting.journal-entries.update', $entry), [
            'entry_date' => '2026-06-15', 'description' => 'Mô tả mới',
        ])->assertSessionHas('success');

        $this->assertSame('Mô tả mới', $entry->fresh()->description);
    }
}
