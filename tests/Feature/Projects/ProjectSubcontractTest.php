<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Fund;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractAcceptance;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: Tạo hợp đồng khoán — không tạo bút toán.
 * TC2: Tạm ứng company — N3312/C1121, advance_amount tăng.
 * TC3: Nghiệm thu company có VAT — N154/N1331/C3312, WIP cost_type=subcontract, retention_amount tăng.
 * TC4: Nghiệm thu team không hóa đơn — N154/C3388, không bắt buộc contractor_id.
 * TC5: Thanh toán sau nghiệm thu — N3312/C1111, còn phải trả giảm.
 * TC6: Giữ lại bảo hành — nghiệm thu đủ vào 154, thanh toán 1 phần, retention vẫn treo.
 * TC7: Hủy nghiệm thu đã posted — JE đảo, WIP cancelled, retention giảm.
 */
class ProjectSubcontractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Fund $fund;
    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $customer = Customer::create(['code' => 'KH-SUB01', 'name' => 'KH Test Subcontract', 'phone' => '0900000010']);
        $this->project = Project::create([
            'code' => 'DA-SUB-TEST', 'name' => 'Dự án test hợp đồng khoán', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $this->user->id,
        ]);

        $this->fund = Fund::create(['code' => 'QUY-TEST', 'name' => 'Quỹ tiền mặt test', 'type' => 'cash', 'account_code' => '1111']);
        $this->bankAccount = BankAccount::create(['name' => 'NH Test', 'bank_name' => 'Vietcombank', 'account_number' => '00011122233', 'account_code' => '1121']);

        foreach (['154', '1331', '3312', '3388', '3341', '1111', '1121', '3335'] as $code) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => "TK {$code}", 'type' => 'asset', 'normal_balance' => 'debit',
                'level' => 2, 'is_detail' => true, 'is_active' => true,
            ]);
        }
    }

    private function makeSubcontract(array $overrides = []): ProjectSubcontract
    {
        $res = $this->post(route('projects.projects.subcontracts.store', $this->project->id), array_merge([
            'contractor_name'   => 'Công ty TNHH Nhà thầu ABC',
            'contractor_type'   => 'company',
            'contract_no'       => 'HDK-001',
            'contract_date'     => '2026-06-01',
            'cost_group'        => 'subcontractor',
            'amount_before_vat' => 100000000,
            'vat_rate'          => 10,
            'vat_amount'        => 10000000,
            'retention_rate'    => 5,
        ], $overrides));

        $res->assertSessionHasNoErrors();

        return ProjectSubcontract::where('project_id', $this->project->id)->latest('id')->first();
    }

    // ─── TC1 ────────────────────────────────────────────────────────────────

    public function test_tc1_create_subcontract_no_journal_entry(): void
    {
        $subcontract = $this->makeSubcontract();

        $this->assertNotNull($subcontract);
        $this->assertEquals('draft', $subcontract->status->value);
        $this->assertEquals(0, \App\Models\JournalEntry::where('reference_type', ProjectSubcontract::class)->count());
    }

    // ─── TC2 ────────────────────────────────────────────────────────────────

    public function test_tc2_advance_company_debits_3312(): void
    {
        $subcontract = $this->makeSubcontract();

        $res = $this->post(route('projects.projects.subcontracts.advances.store', [$this->project->id, $subcontract->id]), [
            'advance_date'   => '2026-06-05',
            'amount'         => 20000000,
            'payment_method' => 'bank',
            'bank_account_id'=> $this->bankAccount->id,
        ]);
        $res->assertSessionHasNoErrors();

        $subcontract->refresh();
        $this->assertEquals(20000000, (float) $subcontract->advance_amount);

        $advance = $subcontract->advances()->first();
        $je = $advance->journalEntry;
        $this->assertNotNull($je);
        $lines = $je->lines;
        $this->assertEquals('3312', $lines->firstWhere('debit', '>', 0)->account_code);
        $this->assertEquals('1121', $lines->firstWhere('credit', '>', 0)->account_code);
        $this->assertEquals(20000000, (float) $lines->firstWhere('debit', '>', 0)->debit);
    }

    // ─── TC3 ────────────────────────────────────────────────────────────────

    public function test_tc3_acceptance_company_with_vat(): void
    {
        $subcontract = $this->makeSubcontract();

        $res = $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_no'     => 'NT-001',
            'acceptance_date'   => '2026-06-10',
            'description'       => 'Nghiệm thu đợt 1',
            'amount_before_vat' => 50000000,
            'vat_rate'          => 10,
            'vat_amount'        => 5000000,
            'invoice_no'        => 'HD-NCC-9999',
            'invoice_date'      => '2026-06-10',
        ]);
        $res->assertSessionHasNoErrors();

        $acceptance = ProjectSubcontractAcceptance::where('subcontract_id', $subcontract->id)->first();
        $this->assertEquals('posted', $acceptance->status);

        $je = $acceptance->journalEntry;
        $lines = $je->lines;
        $this->assertEquals(50000000, (float) $lines->firstWhere('account_code', '154')->debit);
        $this->assertEquals(5000000, (float) $lines->firstWhere('account_code', '1331')->debit);
        $this->assertEquals(55000000, (float) $lines->firstWhere('account_code', '3312')->credit);

        $wip = ProjectWipEntry::where('source_type', ProjectSubcontractAcceptance::class)->where('source_id', $acceptance->id)->first();
        $this->assertNotNull($wip);
        $this->assertEquals('subcontract', $wip->cost_type);
        $this->assertEquals(50000000, (float) $wip->amount, 'WIP phải là phần trước VAT');

        $subcontract->refresh();
        $this->assertEquals(55000000 * 0.05, (float) $subcontract->retention_amount);
    }

    // ─── TC4 ────────────────────────────────────────────────────────────────

    public function test_tc4_acceptance_team_no_invoice(): void
    {
        $subcontract = $this->makeSubcontract([
            'contractor_name' => 'Đội thợ Nguyễn Văn A',
            'contractor_type' => 'team',
            'contractor_id'   => null,
            'vat_rate'        => null,
            'vat_amount'      => 0,
        ]);

        $res = $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date'   => '2026-06-12',
            'description'       => 'Nghiệm thu đội thợ',
            'amount_before_vat' => 15000000,
        ]);
        $res->assertSessionHasNoErrors();

        $acceptance = ProjectSubcontractAcceptance::where('subcontract_id', $subcontract->id)->first();
        $je = $acceptance->journalEntry;
        $lines = $je->lines;
        $this->assertEquals('154', $lines->firstWhere('debit', '>', 0)->account_code);
        $this->assertEquals('3388', $lines->firstWhere('credit', '>', 0)->account_code);
        $this->assertEquals(15000000, (float) $lines->firstWhere('credit', '>', 0)->credit);

        $wip = ProjectWipEntry::where('source_type', ProjectSubcontractAcceptance::class)->where('source_id', $acceptance->id)->first();
        $this->assertEquals('subcontract', $wip->cost_type);
    }

    // ─── TC5 ────────────────────────────────────────────────────────────────

    public function test_tc5_payment_after_acceptance_reduces_amount_due(): void
    {
        $subcontract = $this->makeSubcontract();
        $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date' => '2026-06-10', 'amount_before_vat' => 50000000, 'vat_rate' => 10, 'vat_amount' => 5000000,
        ]);
        $subcontract->refresh();
        $dueBefore = $subcontract->amountDue();
        $this->assertEquals(55000000, $dueBefore);

        $res = $this->post(route('projects.projects.subcontracts.payments.store', [$this->project->id, $subcontract->id]), [
            'payment_date'   => '2026-06-15',
            'amount'         => 30000000,
            'payment_method' => 'cash',
            'fund_id'        => $this->fund->id,
        ]);
        $res->assertSessionHasNoErrors();

        $payment = $subcontract->payments()->first();
        $je = $payment->journalEntry;
        $lines = $je->lines;
        $this->assertEquals('3312', $lines->firstWhere('debit', '>', 0)->account_code);
        $this->assertEquals('1111', $lines->firstWhere('credit', '>', 0)->account_code);

        $subcontract->refresh();
        $this->assertEquals(25000000, $subcontract->amountDue());
    }

    // ─── TC6 ────────────────────────────────────────────────────────────────

    public function test_tc6_retention_stays_in_payable_after_partial_payment(): void
    {
        $subcontract = $this->makeSubcontract(['retention_rate' => 10]);
        $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date' => '2026-06-10', 'amount_before_vat' => 100000000, 'vat_rate' => 10, 'vat_amount' => 10000000,
        ]);
        $subcontract->refresh();

        // Full accepted with VAT = 110,000,000; retention 10% = 11,000,000
        $this->assertEquals(11000000, (float) $subcontract->retention_amount);

        // Thanh toán một phần (giữ lại bảo hành, không trả hết)
        $this->post(route('projects.projects.subcontracts.payments.store', [$this->project->id, $subcontract->id]), [
            'payment_date' => '2026-06-20', 'amount' => 99000000, 'payment_method' => 'bank', 'bank_account_id' => $this->bankAccount->id,
        ]);

        $subcontract->refresh();
        $this->assertEquals(11000000, $subcontract->amountDue(), 'Phần giữ lại bảo hành vẫn treo trong công nợ 3312');
        $this->assertEquals(11000000, (float) $subcontract->retention_amount);
    }

    // ─── TC7 ────────────────────────────────────────────────────────────────

    public function test_tc7_cancel_posted_acceptance_reverses_je_and_wip(): void
    {
        $subcontract = $this->makeSubcontract();
        $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date' => '2026-06-10', 'amount_before_vat' => 50000000, 'vat_rate' => 10, 'vat_amount' => 5000000,
        ]);
        $acceptance = ProjectSubcontractAcceptance::where('subcontract_id', $subcontract->id)->first();
        $jeId = $acceptance->journal_entry_id;
        $subcontract->refresh();
        $this->assertGreaterThan(0, (float) $subcontract->retention_amount);

        $res = $this->delete(route('projects.projects.subcontracts.acceptances.cancel', [$this->project->id, $subcontract->id, $acceptance->id]), [
            'cancel_reason' => 'Test hủy nghiệm thu',
        ]);
        $res->assertSessionHasNoErrors();

        $acceptance->refresh();
        $this->assertEquals('cancelled', $acceptance->status);

        $je = \App\Models\JournalEntry::find($jeId);
        $this->assertEquals('reversed', $je->status);

        $wip = ProjectWipEntry::find($acceptance->project_wip_entry_id);
        $this->assertEquals('cancelled', $wip->status);

        $subcontract->refresh();
        $this->assertEquals(0, (float) $subcontract->retention_amount);
        $this->assertEquals('active', $subcontract->status->value);
    }

    // ─── Phase 2: Khấu trừ TNCN khi thanh toán đội nhóm/cá nhân ────────────────

    public function test_phase2_payment_pit_withholding_splits_credit_lines(): void
    {
        $subcontract = $this->makeSubcontract([
            'contractor_name' => 'Đội thợ B', 'contractor_type' => 'team', 'contractor_id' => null,
            'vat_rate' => null, 'vat_amount' => 0,
        ]);
        $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date' => '2026-06-10', 'amount_before_vat' => 20000000,
        ]);

        $res = $this->post(route('projects.projects.subcontracts.payments.store', [$this->project->id, $subcontract->id]), [
            'payment_date'   => '2026-06-15',
            'amount'         => 20000000,
            'payment_method' => 'cash',
            'fund_id'        => $this->fund->id,
            'pit_withholding_enabled' => true,
            'pit_rate'       => 10,
        ]);
        $res->assertSessionHasNoErrors();

        $payment = $subcontract->payments()->first();
        $this->assertEquals(2000000, (float) $payment->pit_amount);

        $lines = $payment->journalEntry->lines;
        $this->assertEquals(20000000, (float) $lines->firstWhere('account_code', '3388')->debit, 'Nợ 3388 vẫn phải là gross');
        $this->assertEquals(18000000, (float) $lines->firstWhere('account_code', '1111')->credit, 'Có quỹ = net (đã trừ TNCN)');
        $this->assertEquals(2000000, (float) $lines->firstWhere('account_code', '3335')->credit);
    }

    // ─── Phase 2: Chống nhập trùng với Hóa đơn mua đã link acceptance ──────────

    public function test_phase2_duplicate_invoice_number_blocked(): void
    {
        $subcontract = $this->makeSubcontract();
        $supplier  = Supplier::create(['code' => 'NCC-SUB01', 'name' => 'NCC Test Subcontract', 'phone' => '0900000099']);
        $warehouse = \App\Models\Warehouse::create(['name' => 'Kho Test Sub', 'code' => 'KT-SUB']);
        $po = \App\Models\PurchaseOrder::create([
            'code' => 'MH-SUBTEST', 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id,
            'order_date' => '2026-06-09', 'status' => 'sent', 'created_by' => $this->user->id,
        ]);

        PurchaseInvoice::create([
            'code' => 'HD-MH-SUBTEST', 'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id, 'subcontract_id' => $subcontract->id,
            'invoice_number' => 'HD9999', 'invoice_date' => '2026-06-09',
            'subtotal' => 50000000, 'tax_amount' => 5000000, 'total' => 55000000,
            'paid_amount' => 0, 'status' => 'valid', 'created_by' => $this->user->id,
        ]);

        $res = $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date' => '2026-06-10', 'amount_before_vat' => 50000000, 'vat_rate' => 10, 'vat_amount' => 5000000,
            'invoice_no' => 'HD9999',
        ]);

        $res->assertSessionHas('error');
        $this->assertEquals(0, ProjectSubcontractAcceptance::where('subcontract_id', $subcontract->id)->count());
    }

    // ─── Phase 2: Tổng hợp toàn dự án ──────────────────────────────────────────

    public function test_phase2_project_level_summary(): void
    {
        $subcontract = $this->makeSubcontract(['amount_before_vat' => 40000000, 'vat_rate' => 10, 'vat_amount' => 4000000]);
        $this->post(route('projects.projects.subcontracts.acceptances.store', [$this->project->id, $subcontract->id]), [
            'acceptance_date' => '2026-06-10', 'amount_before_vat' => 50000000, 'vat_rate' => 10, 'vat_amount' => 5000000,
        ]);

        $res = $this->get(route('projects.projects.show', $this->project->id));
        $summary = $res->original->getData()['page']['props']['subcontractSummary'];

        $this->assertEquals(44000000, $summary['total_contracts']);
        $this->assertEquals(55000000, $summary['total_accepted']);
        $this->assertEquals(50000000, $summary['total_wip_154']);
        // Nghiệm thu (55tr) > giá trị HĐ (44tr) → vượt hợp đồng 11tr
        $this->assertEquals(11000000, $summary['total_over_budget']);
    }
}
