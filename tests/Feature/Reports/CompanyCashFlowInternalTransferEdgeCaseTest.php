<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\InternalBankAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\CashFlowClassificationService;
use App\Services\CashFlowInternalTransferMatchingService;
use App\Services\CompanyCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Hardening pass — spec §5/§6: edge case cặp đôi chuyển khoản nội bộ. */
class CompanyCashFlowInternalTransferEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private CashFlowInternalTransferMatchingService $matcher;
    private CompanyCashFlowReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $admin->roles()->sync([$adminRole->id]);
        $this->actingAs($admin);
        $this->matcher = app(CashFlowInternalTransferMatchingService::class);
        $this->report = app(CompanyCashFlowReportService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function account(string $name): BankAccount
    {
        return BankAccount::create(['name' => $name, 'bank_name' => $name, 'account_number' => (string) rand(10000, 99999), 'opening_balance' => 0, 'is_active' => true]);
    }

    private function internal(): InternalBankAccount
    {
        return InternalBankAccount::create(['name' => 'Cty', 'account_number' => (string) rand(10000, 99999), 'bank_name' => 'BIDV']);
    }

    private function tx(BankAccount $acc, string $date, float $debit = 0, float $credit = 0, array $extra = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'bank_account_id' => $acc->id, 'transaction_date' => $date,
            'description' => 'GD test', 'debit' => $debit, 'credit' => $credit,
        ], $extra));
    }

    /** Case A — khác ngày trong window vẫn được gợi ý. */
    public function test_suggests_pair_across_different_dates_within_window(): void
    {
        $vcb = $this->account('VCB');
        $bidv = $this->account('BIDV');
        $internal = $this->internal();

        $out = $this->tx($vcb, '2026-09-10', debit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $in = $this->tx($bidv, '2026-09-11', credit: 300_000_000, extra: ['internal_account_id' => $internal->id]);

        $suggestions = $this->matcher->suggestPairs();

        $this->assertCount(1, $suggestions);
        $this->assertFalse($suggestions[0]['ambiguous']);
        $this->assertSame($in->id, $suggestions[0]['candidates'][0]->id);
    }

    /** Case B — nhiều giao dịch đi trùng số tiền -> KHÔNG được tự chọn bừa, phải đánh dấu ambiguous. */
    public function test_marks_ambiguous_when_multiple_candidates_match_same_amount(): void
    {
        $vcb = $this->account('VCB');
        $bidv = $this->account('BIDV');
        $internal = $this->internal();

        $out = $this->tx($vcb, '2026-09-10', debit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $in1 = $this->tx($bidv, '2026-09-10', credit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $in2 = $this->tx($bidv, '2026-09-11', credit: 300_000_000, extra: ['internal_account_id' => $internal->id]);

        $suggestions = $this->matcher->suggestPairs();

        $this->assertCount(1, $suggestions);
        $this->assertTrue($suggestions[0]['ambiguous']);
        $this->assertCount(2, $suggestions[0]['candidates']);

        // Không tự confirm — cả 2 giao dịch đến vẫn phải còn paired_transaction_id = null.
        $this->assertNull($in1->fresh()->paired_transaction_id);
        $this->assertNull($in2->fresh()->paired_transaction_id);
    }

    /** Case C — không được cặp đôi nếu 1 trong 2 phía không xác định là tài khoản nội bộ công ty. */
    public function test_confirm_pair_rejects_when_counterpart_not_internal_account(): void
    {
        $vcb = $this->account('VCB');
        $bidv = $this->account('BIDV');
        $internal = $this->internal();

        $out = $this->tx($vcb, '2026-09-10', debit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $external = $this->tx($bidv, '2026-09-10', credit: 300_000_000); // không có internal_account_id

        $this->expectException(RuntimeException::class);
        $this->matcher->confirmPair($out, $external);
    }

    /** Case D — unpair phải làm consolidated report tính lại đúng (không còn net = 0). */
    public function test_unpair_recalculates_consolidated_report(): void
    {
        $vcb = $this->account('VCB');
        $bidv = $this->account('BIDV');
        $internal = $this->internal();

        $out = $this->tx($vcb, '2026-09-05', debit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $in = $this->tx($bidv, '2026-09-05', credit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $this->matcher->confirmPair($out, $in);

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $this->assertSame(0.0, $this->report->summary($filters)['net_cash_flow']);

        $this->matcher->unpair($out->fresh());

        $after = $this->report->summary($filters);
        $this->assertSame(300_000_000.0, $after['inflow']);
        $this->assertSame(300_000_000.0, $after['outflow']);
        $this->assertSame(0.0, $after['net_cash_flow']); // 300tr in - 300tr out = 0 nhưng KHÔNG còn bị loại trừ như cặp nội bộ
        $this->assertNull($out->fresh()->paired_transaction_id);
        $this->assertNull($in->fresh()->paired_transaction_id);
    }

    /**
     * §6 — Transaction đã PHÂN LOẠI là "chuyển tiền nội bộ" nhưng CHƯA/KHÔNG cặp đôi được
     * (ví dụ counterpart chưa import) KHÔNG được biến thành thu/chi thật trong consolidated
     * report — classification quyết định treatment, độc lập với việc pairing có thành công
     * hay không.
     */
    public function test_classification_as_internal_transfer_excluded_from_consolidated_even_without_pairing(): void
    {
        $vcb = $this->account('VCB');
        $internal = $this->internal();
        $outCategory = CashFlowCategory::where('code', 'out_internal_transfer')->first();

        $out = $this->tx($vcb, '2026-09-05', debit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        app(CashFlowClassificationService::class)->update($out, ['cash_flow_category_id' => $outCategory->id]);

        $this->assertNull($out->fresh()->paired_transaction_id, 'Chưa cặp đôi — counterpart giả định chưa import');

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $consolidated = $this->report->summary($filters);
        $this->assertSame(0.0, $consolidated['outflow'], 'Consolidated không được tính giao dịch nội bộ chưa cặp như chi thật');

        // Nhưng xem riêng account VCB vẫn phải thấy đúng số tiền thực đã ra (spec §5).
        $perAccount = $this->report->summary(array_merge($filters, ['bank_account_id' => $vcb->id]));
        $this->assertSame(300_000_000.0, $perAccount['outflow']);
    }
}
