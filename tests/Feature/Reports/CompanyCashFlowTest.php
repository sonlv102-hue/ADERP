<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashFlowClassificationService;
use App\Services\CashFlowInternalTransferMatchingService;
use App\Services\CompanyCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyCashFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CompanyCashFlowReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]);
        $this->actingAs($this->admin);
        $this->report = app(CompanyCashFlowReportService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function account(string $name, float $opening = 0): BankAccount
    {
        return BankAccount::create([
            'name' => $name, 'bank_name' => $name, 'account_number' => '00'.rand(1000, 9999),
            'opening_balance' => $opening, 'is_active' => true,
        ]);
    }

    private function tx(BankAccount $acc, string $date, float $debit = 0, float $credit = 0, array $extra = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'bank_account_id' => $acc->id, 'transaction_date' => $date,
            'description' => 'GD test', 'debit' => $debit, 'credit' => $credit,
        ], $extra));
    }

    /** T1 — đầu kỳ 1 tỷ, +500tr thu KH, -200tr chi NCC */
    public function test_t1_basic_period_math(): void
    {
        $acc = $this->account('VCB', 1_000_000_000);
        $this->tx($acc, '2026-09-05', credit: 500_000_000);
        $this->tx($acc, '2026-09-10', debit: 200_000_000);

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(500_000_000.0, $summary['inflow']);
        $this->assertSame(200_000_000.0, $summary['outflow']);
        $this->assertSame(300_000_000.0, $summary['net_cash_flow']);
        $this->assertSame(1_300_000_000.0, $summary['closing_balance']);
    }

    /** T2 — VCB→BIDV 300tr: từng account đúng, toàn công ty net = 0 */
    public function test_t2_internal_transfer_nets_to_zero_consolidated_but_not_per_account(): void
    {
        $vcb = $this->account('VCB', 1_000_000_000);
        $bidv = $this->account('BIDV', 500_000_000);
        $internal = \App\Models\InternalBankAccount::create(['name' => 'Cty', 'account_number' => 'X', 'bank_name' => 'BIDV']);

        $out = $this->tx($vcb, '2026-09-05', debit: 300_000_000, extra: ['internal_account_id' => $internal->id]);
        $in = $this->tx($bidv, '2026-09-05', credit: 300_000_000, extra: ['internal_account_id' => $internal->id]);

        app(CashFlowInternalTransferMatchingService::class)->confirmPair($out, $in);

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];

        $vcbView = $this->report->summary(array_merge($filters, ['bank_account_id' => $vcb->id]));
        $this->assertSame(300_000_000.0, $vcbView['outflow'], 'Xem riêng VCB vẫn phải thấy -300tr');

        $bidvView = $this->report->summary(array_merge($filters, ['bank_account_id' => $bidv->id]));
        $this->assertSame(300_000_000.0, $bidvView['inflow'], 'Xem riêng BIDV vẫn phải thấy +300tr');

        $companyView = $this->report->summary($filters);
        $this->assertSame(0.0, $companyView['net_cash_flow'], 'Toàn công ty phải net = 0');
    }

    /** T3 — regression: import_hash unique đã có sẵn, không cho trùng */
    public function test_t3_import_dedup_still_enforced(): void
    {
        $acc = $this->account('VCB');
        BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => '2026-09-01',
            'description' => 'GD 1', 'credit' => 100, 'import_hash' => 'hash-abc',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => '2026-09-01',
            'description' => 'GD 1 dup', 'credit' => 100, 'import_hash' => 'hash-abc',
        ]);
    }

    /** T4 — gán KH+category → reconcileStatus() bump ngay, KPI chưa xác định giảm ngay */
    public function test_t4_classification_updates_dashboard_immediately(): void
    {
        $acc = $this->account('VCB');
        $customer = Customer::create(['code' => 'KH-CF1', 'name' => 'Cty ABC', 'is_active' => true]);
        $t = $this->tx($acc, '2026-09-10', credit: 100_000_000);

        $before = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertSame(100_000_000.0, $before['unclassified_inflow']);
        $this->assertSame(\App\Enums\CashFlowReconcileStatus::Unclassified, $t->reconcileStatus());

        $category = \App\Models\CashFlowCategory::where('code', 'in_customer_payment')->first();
        app(CashFlowClassificationService::class)->update($t, [
            'party_type' => 'customer', 'party_id' => $customer->id, 'party_name' => $customer->name,
            'cash_flow_category_id' => $category->id,
        ]);

        $after = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertSame(0.0, $after['unclassified_inflow']);
        $this->assertSame(\App\Enums\CashFlowReconcileStatus::Categorized, $t->fresh()->reconcileStatus());
    }

    /** T5 — filter theo kỳ + dự án chỉ tính đúng tập lọc */
    public function test_t5_filters_scope_all_reports(): void
    {
        $acc = $this->account('VCB');
        $projCustomer = Customer::create(['code' => 'KH-CF2', 'name' => 'KH Dự án', 'is_active' => true]);
        $project = Project::create(['code' => 'DA-CF1', 'name' => 'Gia Bình', 'status' => 'planning', 'customer_id' => $projCustomer->id, 'created_by' => $this->admin->id]);
        $other = Project::create(['code' => 'DA-CF2', 'name' => 'Khác', 'status' => 'planning', 'customer_id' => $projCustomer->id, 'created_by' => $this->admin->id]);

        $this->tx($acc, '2026-09-05', credit: 8_500_000_000, extra: ['project_id' => $project->id]);
        $this->tx($acc, '2026-09-06', debit: 6_200_000_000, extra: ['project_id' => $project->id]);
        $this->tx($acc, '2026-09-07', credit: 999_000_000, extra: ['project_id' => $other->id]);
        $this->tx($acc, '2026-08-01', credit: 1_000_000_000, extra: ['project_id' => $project->id]); // ngoài kỳ

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'project_id' => $project->id];
        $summary = $this->report->summary($filters);

        $this->assertSame(8_500_000_000.0, $summary['inflow']);
        $this->assertSame(6_200_000_000.0, $summary['outflow']);
    }

    /** T6 — user không có quyền → 403 backend */
    public function test_t6_permission_enforced_on_backend(): void
    {
        $plain = User::factory()->create(['is_active' => true]);
        $this->actingAs($plain)
            ->get(route('reports.company-cashflow.index'))
            ->assertForbidden();
    }

    /** §22 — whitelist reject field không cho sửa (thử set debit) */
    public function test_t7_classification_service_whitelists_fields(): void
    {
        $acc = $this->account('VCB');
        $t = $this->tx($acc, '2026-09-10', credit: 100);

        app(CashFlowClassificationService::class)->update($t, [
            'cash_flow_note' => 'ok',
            'debit' => 999_999_999, // KHÔNG được whitelist
            'description' => 'hacked',
        ]);

        $fresh = $t->fresh();
        $this->assertSame('ok', $fresh->cash_flow_note);
        $this->assertSame(0.0, (float) $fresh->debit);
        $this->assertSame('GD test', $fresh->description);
    }

    /** Smoke: trang index render OK cho user có quyền (không chỉ test forbidden path) */
    public function test_index_page_renders_for_permitted_user(): void
    {
        $acc = $this->account('VCB', 100);
        $this->tx($acc, '2026-09-10', credit: 100);

        $this->get(route('reports.company-cashflow.index'))->assertOk();
    }

    /** §21 — activity_log có old/new khi classify */
    public function test_t8_activity_log_records_old_and_new(): void
    {
        $acc = $this->account('VCB');
        $supplier = Supplier::create(['code' => 'NCC-CF1', 'name' => 'NCC A', 'is_active' => true]);
        $t = $this->tx($acc, '2026-09-10', debit: 50_000_000);

        app(CashFlowClassificationService::class)->update($t, [
            'party_type' => 'supplier', 'party_id' => $supplier->id, 'party_name' => $supplier->name,
        ]);

        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $t->id,
            'subject_type' => BankTransaction::class,
            'description' => 'classify',
        ]);
        $log = \Spatie\Activitylog\Models\Activity::where('subject_id', $t->id)
            ->where('subject_type', BankTransaction::class)
            ->latest()->first();
        $this->assertSame('supplier', $log->properties['new']['party_type']);
        $this->assertNull($log->properties['old']['party_type']);
    }
}
