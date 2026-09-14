<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\InternalBankAccount;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\CashFlowClassificationService;
use App\Services\CashFlowInternalTransferMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hardening pass — spec §13/§14: partial update KHÔNG được null hoá field không gửi;
 * pair/unpair phải có audit đầy đủ (ai, lúc nào, transaction nào với transaction nào).
 * Đây là regression rất quan trọng — Phase 1 từng có lỗi data loss tương tự
 * (ClassifyModal.vue không pre-fill party_id/project_id, xem CompanyCashFlowFilterAndAuthTest).
 */
class CompanyCashFlowPartialUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CashFlowClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]);
        $this->actingAs($this->admin);
        $this->service = app(CashFlowClassificationService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function account(): BankAccount
    {
        return BankAccount::create(['name' => 'VCB', 'bank_name' => 'VCB', 'account_number' => (string) rand(1000, 9999), 'opening_balance' => 0, 'is_active' => true]);
    }

    /**
     * Giao dịch đã có ĐỦ party+project+contract+responsible+category. Request chỉ gửi
     * `cash_flow_note` (đúng như payload PATCH tối thiểu) -> mọi field còn lại PHẢI
     * giữ nguyên, không bị set về NULL.
     */
    public function test_partial_update_with_only_note_preserves_all_other_fields(): void
    {
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-PU1', 'name' => 'KH PU1', 'is_active' => true]);
        $project = Project::create(['code' => 'DA-PU1', 'name' => 'DA PU1', 'status' => 'planning', 'customer_id' => $customer->id, 'created_by' => $this->admin->id]);
        $contract = Contract::create(['code' => 'HD-PU1', 'customer_id' => $customer->id, 'title' => 'HĐ PU1', 'status' => 'draft', 'created_by' => $this->admin->id]);
        $responsible = User::factory()->create(['is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $t = BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => '2026-09-10',
            'description' => 'GD test', 'credit' => 100,
        ]);
        $this->service->update($t, [
            'party_type' => 'customer', 'party_id' => $customer->id,
            'project_id' => $project->id,
            'contract_type' => 'contract', 'contract_id' => $contract->id,
            'responsible_user_id' => $responsible->id,
            'cash_flow_category_id' => $category->id,
        ]);

        // Payload CHỈ có note — đúng như 1 request PATCH tối thiểu, không phải full DTO.
        $this->service->update($t, ['cash_flow_note' => 'chỉ sửa note']);

        $fresh = $t->fresh();
        $this->assertSame('customer', $fresh->party_type);
        $this->assertSame($customer->id, $fresh->party_id);
        $this->assertSame($customer->name, $fresh->party_name);
        $this->assertSame($project->id, $fresh->project_id);
        $this->assertSame('contract', $fresh->contract_type);
        $this->assertSame($contract->id, $fresh->contract_id);
        $this->assertSame($responsible->id, $fresh->responsible_user_id);
        $this->assertSame($category->id, $fresh->cash_flow_category_id);
        $this->assertSame('chỉ sửa note', $fresh->cash_flow_note);
    }

    /** Cùng kịch bản nhưng gọi qua HTTP thật (route classify) với payload tối thiểu. */
    public function test_partial_update_via_http_endpoint_preserves_fields(): void
    {
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-PU2', 'name' => 'KH PU2', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();
        $t = BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => '2026-09-10',
            'description' => 'GD test', 'credit' => 100,
        ]);
        $this->service->update($t, ['party_type' => 'customer', 'party_id' => $customer->id, 'cash_flow_category_id' => $category->id]);

        $this->post(route('reports.company-cashflow.classify', $t->id), ['cash_flow_note' => 'note qua http'])
            ->assertRedirect();

        $fresh = $t->fresh();
        $this->assertSame($customer->id, $fresh->party_id);
        $this->assertSame($category->id, $fresh->cash_flow_category_id);
        $this->assertSame('note qua http', $fresh->cash_flow_note);
    }

    /** §5 — unpair KHÔNG được tự động đổi category đã phân loại "chuyển tiền nội bộ". */
    public function test_unpair_does_not_change_category(): void
    {
        $vcb = $this->account();
        $bidv = $this->account();
        $internal = InternalBankAccount::create(['name' => 'Cty', 'account_number' => 'X9', 'bank_name' => 'BIDV']);
        $outCategory = CashFlowCategory::where('code', 'out_internal_transfer')->value('id');
        $inCategory = CashFlowCategory::where('code', 'in_internal_transfer')->value('id');

        $out = BankTransaction::create(['bank_account_id' => $vcb->id, 'transaction_date' => '2026-09-05', 'description' => 'CK đi', 'debit' => 300, 'internal_account_id' => $internal->id, 'cash_flow_category_id' => $outCategory]);
        $in = BankTransaction::create(['bank_account_id' => $bidv->id, 'transaction_date' => '2026-09-05', 'description' => 'CK đến', 'credit' => 300, 'internal_account_id' => $internal->id, 'cash_flow_category_id' => $inCategory]);

        $matcher = app(CashFlowInternalTransferMatchingService::class);
        $matcher->confirmPair($out, $in);
        $matcher->unpair($out->fresh());

        $this->assertSame($outCategory, $out->fresh()->cash_flow_category_id);
        $this->assertSame($inCategory, $in->fresh()->cash_flow_category_id);
        $this->assertNull($out->fresh()->paired_transaction_id);
    }

    /** §14 — pair phải audit: ai, lúc nào, transaction nào với transaction nào. */
    public function test_pair_is_audited_with_causer_and_both_transaction_ids(): void
    {
        $vcb = $this->account();
        $bidv = $this->account();
        $internal = InternalBankAccount::create(['name' => 'Cty', 'account_number' => 'X10', 'bank_name' => 'BIDV']);
        $out = BankTransaction::create(['bank_account_id' => $vcb->id, 'transaction_date' => '2026-09-05', 'description' => 'CK đi', 'debit' => 300, 'internal_account_id' => $internal->id]);
        $in = BankTransaction::create(['bank_account_id' => $bidv->id, 'transaction_date' => '2026-09-05', 'description' => 'CK đến', 'credit' => 300, 'internal_account_id' => $internal->id]);

        app(CashFlowInternalTransferMatchingService::class)->confirmPair($out, $in);

        $log = \Spatie\Activitylog\Models\Activity::where('description', 'pair_internal_transfer')
            ->where('subject_id', $out->id)->where('subject_type', BankTransaction::class)->latest()->first();

        $this->assertNotNull($log, 'Không tìm thấy activity log cho hành động pair');
        $this->assertSame($this->admin->id, $log->causer_id, 'Audit phải ghi ai thực hiện pair');
        $this->assertSame($in->id, $log->properties['paired_with']);
        $this->assertNotNull($log->created_at, 'Audit phải có timestamp');
    }

    /** §14 — unpair phải audit: ai, lúc nào, old pair = ai, new pair = null. */
    public function test_unpair_is_audited_with_causer_and_old_pair(): void
    {
        $vcb = $this->account();
        $bidv = $this->account();
        $internal = InternalBankAccount::create(['name' => 'Cty', 'account_number' => 'X11', 'bank_name' => 'BIDV']);
        $out = BankTransaction::create(['bank_account_id' => $vcb->id, 'transaction_date' => '2026-09-05', 'description' => 'CK đi', 'debit' => 300, 'internal_account_id' => $internal->id]);
        $in = BankTransaction::create(['bank_account_id' => $bidv->id, 'transaction_date' => '2026-09-05', 'description' => 'CK đến', 'credit' => 300, 'internal_account_id' => $internal->id]);
        $matcher = app(CashFlowInternalTransferMatchingService::class);
        $matcher->confirmPair($out, $in);

        $matcher->unpair($out->fresh());

        $log = \Spatie\Activitylog\Models\Activity::where('description', 'unpair_internal_transfer')
            ->where('subject_id', $out->id)->where('subject_type', BankTransaction::class)->latest()->first();

        $this->assertNotNull($log, 'Không tìm thấy activity log cho hành động unpair');
        $this->assertSame($this->admin->id, $log->causer_id, 'Audit phải ghi ai thực hiện unpair');
        $this->assertSame($in->id, $log->properties['was_paired_with']);
        $this->assertNull($out->fresh()->paired_transaction_id, 'new pair phải là null sau unpair');
    }
}
