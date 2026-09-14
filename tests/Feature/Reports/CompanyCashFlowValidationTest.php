<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashFlowClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/** Hardening pass — spec §3/§4/§9/§12: validate party/contract/category, chống concurrency. */
class CompanyCashFlowValidationTest extends TestCase
{
    use RefreshDatabase;

    private CashFlowClassificationService $service;
    private User $admin;

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

    private function tx(float $debit = 0, float $credit = 0): BankTransaction
    {
        $acc = BankAccount::create(['name' => 'VCB', 'bank_name' => 'VCB', 'account_number' => (string) rand(1000, 9999), 'opening_balance' => 0, 'is_active' => true]);

        return BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => '2026-09-10',
            'description' => 'GD test', 'debit' => $debit, 'credit' => $credit,
        ]);
    }

    /** §3 — party_id không tồn tại trong bảng tương ứng phải bị từ chối. */
    public function test_rejects_nonexistent_party_id(): void
    {
        $t = $this->tx(credit: 100);

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['party_type' => 'customer', 'party_id' => 999999]);
    }

    /** §3 — party_id thuộc BẢNG SAI (id của supplier) không được chấp nhận cho party_type=customer. */
    public function test_rejects_party_id_from_wrong_table(): void
    {
        $t = $this->tx(credit: 100);
        $supplier = Supplier::create(['code' => 'NCC-V1', 'name' => 'NCC V1', 'is_active' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['party_type' => 'customer', 'party_id' => $supplier->id]);
    }

    /** §3 — party_type không nằm trong whitelist (thử inject tên class PHP) phải bị từ chối,
     *  không được dùng để resolve model tùy ý. */
    public function test_rejects_arbitrary_party_type_string(): void
    {
        $t = $this->tx(credit: 100);

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['party_type' => \App\Models\User::class, 'party_id' => 1]);
    }

    /** party_type có master data thật -> party_name server-side lấy từ model, không tin free text client. */
    public function test_party_name_resolved_from_model_not_client_input(): void
    {
        $t = $this->tx(credit: 100);
        $customer = Customer::create(['code' => 'KH-V1', 'name' => 'Tên đúng', 'is_active' => true]);

        $this->service->update($t, ['party_type' => 'customer', 'party_id' => $customer->id, 'party_name' => 'Tên giả mạo']);

        $this->assertSame('Tên đúng', $t->fresh()->party_name);
    }

    /** §3 — contract_id không tồn tại trong bảng tương ứng phải bị từ chối. */
    public function test_rejects_nonexistent_contract_id(): void
    {
        $t = $this->tx(credit: 100);

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['contract_type' => 'contract', 'contract_id' => 999999]);
    }

    public function test_accepts_valid_contract(): void
    {
        $t = $this->tx(credit: 100);
        $customer = Customer::create(['code' => 'KH-V2', 'name' => 'KH V2', 'is_active' => true]);
        $contract = Contract::create(['code' => 'HD-V1', 'customer_id' => $customer->id, 'title' => 'HĐ test', 'status' => 'draft', 'created_by' => $this->admin->id]);

        $this->service->update($t, ['contract_type' => 'contract', 'contract_id' => $contract->id]);

        $this->assertSame($contract->id, $t->fresh()->contract_id);
    }

    /** §4 — gán category "Tiền vào" cho giao dịch debit (tiền ra) phải bị từ chối. */
    public function test_rejects_category_direction_mismatch_inflow_on_outflow_transaction(): void
    {
        $t = $this->tx(debit: 100); // tiền ra
        $inCategory = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['cash_flow_category_id' => $inCategory->id]);
    }

    public function test_rejects_category_direction_mismatch_outflow_on_inflow_transaction(): void
    {
        $t = $this->tx(credit: 100); // tiền vào
        $outCategory = CashFlowCategory::where('code', 'out_supplier_payment')->first();

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['cash_flow_category_id' => $outCategory->id]);
    }

    /** §9 — category đã bị vô hiệu hoá không được gán MỚI cho giao dịch khác. */
    public function test_rejects_newly_assigning_disabled_category(): void
    {
        $t = $this->tx(credit: 100);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();
        $category->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['cash_flow_category_id' => $category->id]);
    }

    /** §9 — category cũ đã gán từ trước bị vô hiệu hoá SAU đó vẫn giữ nguyên nhãn lịch sử,
     *  và không bị lỗi khi cập nhật field KHÁC (không đổi category). */
    public function test_keeping_existing_disabled_category_unchanged_does_not_error(): void
    {
        $t = $this->tx(credit: 100);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();
        $this->service->update($t, ['cash_flow_category_id' => $category->id]);

        $category->update(['is_active' => false]);

        $this->service->update($t, ['cash_flow_note' => 'ghi chú mới']);

        $fresh = $t->fresh();
        $this->assertSame($category->id, $fresh->cash_flow_category_id);
        $this->assertSame('ghi chú mới', $fresh->cash_flow_note);
    }

    /** §12 — concurrency: expected_updated_at lệch với bản ghi hiện tại (đã bị người khác sửa) -> từ chối. */
    public function test_rejects_update_with_stale_expected_updated_at(): void
    {
        $t = $this->tx(credit: 100);
        $staleTimestamp = $t->updated_at->subMinutes(10)->toJSON();

        $this->expectException(ValidationException::class);
        $this->service->update($t, ['cash_flow_note' => 'x', 'expected_updated_at' => $staleTimestamp]);
    }

    public function test_accepts_update_with_matching_expected_updated_at(): void
    {
        $t = $this->tx(credit: 100);

        $result = $this->service->update($t, ['cash_flow_note' => 'ok', 'expected_updated_at' => $t->updated_at->toJSON()]);

        $this->assertSame('ok', $result->cash_flow_note);
    }

    /** §21 — audit ghi đủ TẤT CẢ field thay đổi trong 1 lần cập nhật, không chỉ 1 field. */
    public function test_audit_log_records_all_changed_fields_in_single_update(): void
    {
        $t = $this->tx(credit: 100);
        $customer = Customer::create(['code' => 'KH-V3', 'name' => 'KH V3', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $this->service->update($t, [
            'party_type' => 'customer', 'party_id' => $customer->id,
            'cash_flow_category_id' => $category->id,
            'cash_flow_note' => 'nhiều field cùng lúc',
        ]);

        $log = \Spatie\Activitylog\Models\Activity::where('subject_id', $t->id)
            ->where('subject_type', BankTransaction::class)->latest()->first();

        foreach (['party_type', 'party_id', 'party_name', 'cash_flow_category_id', 'cash_flow_note'] as $field) {
            $this->assertArrayHasKey($field, $log->properties['new'], "Thiếu field {$field} trong audit log");
        }
    }
}
