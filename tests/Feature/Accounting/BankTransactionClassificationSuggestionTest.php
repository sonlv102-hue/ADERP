<?php

namespace Tests\Feature\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\Employee;
use App\Models\InternalBankAccount;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Services\BankTransactionClassificationSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BankTransactionClassificationSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private BankTransactionClassificationSuggestionService $svc;
    private BankAccount $bankAccount;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
        $this->svc = app(BankTransactionClassificationSuggestionService::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        $this->bankAccount = BankAccount::create([
            'name' => 'VCB', 'bank_name' => 'VCB', 'account_number' => '1234567890',
            'account_code' => '1121', 'currency' => 'VND', 'opening_balance' => 0,
            'is_active' => true, 'created_by' => $this->user->id,
        ]);
    }

    private function tx(array $extra): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'bank_account_id' => $this->bankAccount->id,
            'transaction_date' => '2026-09-10',
            'description' => 'GD test',
            'debit' => 0, 'credit' => 0,
        ], $extra));
    }

    /** Case 1 — STK đối ứng thuộc nhân viên + nội dung có từ khóa lương => gợi ý Trả lương */
    public function test_case1_employee_account_suggests_salary(): void
    {
        $employee = Employee::create([
            'code' => 'NV-001', 'name' => 'Nguyễn Văn A', 'status' => 'active',
            'base_salary' => 10_000_000, 'bank_account_no' => '098111222',
            'created_by' => $this->user->id,
        ]);

        $tx = $this->tx(['debit' => 15_000_000, 'description' => 'Thanh toan luong thang 9', 'counterpart_account' => '098111222', 'counterpart_name' => 'NGUYEN VAN A', 'counterpart_bank' => 'VCB']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('employee', $result['party_type']);
        $this->assertSame($employee->id, $result['party_id']);
        $this->assertSame('out_salary', CashFlowCategory::find($result['category_id'])->code);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    /**
     * Tighten rule (Part 1) — TK/tên khớp nhân viên nhưng KHÔNG có từ khóa lương trong nội dung
     * => KHÔNG được tự gán "Trả lương" (tránh false positive), chỉ gợi ý nhẹ để Admin tự quyết định.
     */
    public function test_employee_match_without_salary_keyword_does_not_suggest_salary(): void
    {
        Employee::create([
            'code' => 'NV-020', 'name' => 'Nguyen Van E', 'status' => 'active',
            'base_salary' => 10_000_000, 'bank_account_no' => '098222333',
            'created_by' => $this->user->id,
        ]);

        $tx = $this->tx(['debit' => 3_000_000, 'description' => 'Chuyen khoan', 'counterpart_account' => '098222333', 'counterpart_name' => 'NGUYEN VAN E']);

        $result = $this->svc->suggest($tx);

        $this->assertNull($result['category_id']);
        $this->assertNull($result['category_name']);
        $this->assertSame('employee', $result['party_type']);
        $this->assertLessThanOrEqual(70, $result['confidence']);
    }

    /** Case 2 — STK đối ứng thuộc NCC => gợi ý Thanh toán NCC */
    public function test_case2_supplier_account_suggests_supplier_payment(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-0001', 'name' => 'Công ty ABC', 'is_active' => true]);
        SupplierBankAccount::create([
            'supplier_id' => $supplier->id, 'bank_name' => 'MB Bank',
            'account_number' => '19021427359016', 'normalized_account_number' => '19021427359016',
            'is_active' => true,
        ]);

        $tx = $this->tx(['debit' => 5_000_000, 'counterpart_account' => '19021427359016', 'counterpart_name' => 'CONG TY ABC', 'counterpart_bank' => 'MB Bank']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('supplier', $result['party_type']);
        $this->assertSame($supplier->id, $result['party_id']);
        $this->assertSame('out_supplier_payment', CashFlowCategory::find($result['category_id'])->code);
    }

    /** Case 3 — STK đối ứng thuộc khách hàng => gợi ý Thu tiền khách hàng */
    public function test_case3_customer_account_suggests_customer_payment(): void
    {
        $customer = Customer::create(['code' => 'KH-0001', 'name' => 'Công ty XYZ', 'is_active' => true]);
        CustomerBankAccount::create([
            'customer_id' => $customer->id, 'bank_name' => 'VCB',
            'account_number' => '999999', 'normalized_account_number' => '999999',
            'account_name' => 'CONG TY XYZ', 'is_active' => true,
        ]);

        $tx = $this->tx(['credit' => 20_000_000, 'counterpart_account' => '999999', 'counterpart_name' => 'CONG TY XYZ', 'counterpart_bank' => 'VCB']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('customer', $result['party_type']);
        $this->assertSame($customer->id, $result['party_id']);
        $this->assertSame('in_customer_payment', CashFlowCategory::find($result['category_id'])->code);
    }

    /** Case 4 — không có mapping nào khớp => KHÔNG đưa ra gợi ý sai */
    public function test_case4_no_mapping_gives_no_suggestion(): void
    {
        $tx = $this->tx(['debit' => 1_000_000, 'counterpart_account' => '000000000', 'counterpart_name' => 'NGUOI LA', 'counterpart_bank' => 'ABC BANK']);

        $result = $this->svc->suggest($tx);

        $this->assertFalse($result['suggestion']);
        $this->assertLessThan(60, $result['confidence']);
    }

    /** Chuyển khoản nội bộ — STK đối ứng thuộc danh sách TK công ty */
    public function test_internal_transfer_account_suggests_internal_category(): void
    {
        InternalBankAccount::create(['name' => 'TK Techcombank công ty', 'account_number' => '5555555', 'bank_name' => 'Techcombank', 'is_active' => true]);

        $tx = $this->tx(['debit' => 3_000_000, 'counterpart_account' => '5555555', 'counterpart_bank' => 'Techcombank']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('bank', $result['party_type']);
        $this->assertSame('out_internal_transfer', CashFlowCategory::find($result['category_id'])->code);
        $this->assertSame(100, $result['confidence']);
    }

    /** Học từ xác nhận của Admin: sau khi confirm qua endpoint classify, mapping được lưu và dùng cho gợi ý lần sau. */
    public function test_confirming_via_classify_endpoint_learns_mapping_for_next_suggestion(): void
    {
        $tx = $this->tx(['debit' => 2_000_000, 'counterpart_account' => '77778888', 'counterpart_name' => 'DOI TAC MOI', 'counterpart_bank' => 'ACB']);
        $category = CashFlowCategory::where('code', 'out_office_expense')->firstOrFail();

        $this->post(route('accounting.bank-accounts.transactions.classify', [$this->bankAccount->id, $tx->id]), [
            'cash_flow_category_id' => $category->id,
            'party_type' => 'other_entity',
            'party_name' => 'Đối tác mới',
        ])->assertRedirect();

        $this->assertDatabaseHas('bank_counterparty_mappings', [
            'bank_account_number' => '77778888',
            'party_type' => 'other_entity',
            'cash_flow_category_id' => $category->id,
        ]);

        $tx2 = $this->tx(['debit' => 1_500_000, 'counterpart_account' => '77778888', 'counterpart_bank' => 'ACB']);
        $result = $this->svc->suggest($tx2);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('other_entity', $result['party_type']);
        $this->assertSame($category->id, $result['category_id']);
    }

    /** API endpoint trả đúng cấu trúc JSON gợi ý. */
    public function test_classification_suggestion_endpoint_returns_json(): void
    {
        Employee::create(['code' => 'NV-002', 'name' => 'Trần Thị B', 'status' => 'active', 'base_salary' => 8_000_000, 'bank_account_no' => '0912345', 'created_by' => $this->user->id]);
        $tx = $this->tx(['debit' => 5_000_000, 'counterpart_account' => '0912345']);

        $response = $this->getJson(route('accounting.bank-accounts.transactions.classification-suggestion', [$this->bankAccount->id, $tx->id]));

        $response->assertOk()->assertJson(['suggestion' => true, 'party_type' => 'employee']);
    }

    /** Rule bổ sung 1 — money_out + nội dung có từ khóa lương + counterparty là nhân viên (khớp số TK) => confidence >= 95, có lý do nội dung. */
    public function test_salary_keyword_boosts_employee_account_match_confidence(): void
    {
        Employee::create(['code' => 'NV-010', 'name' => 'Le Van C', 'status' => 'active', 'base_salary' => 12_000_000, 'bank_account_no' => '0288888', 'created_by' => $this->user->id]);

        $tx = $this->tx(['debit' => 12_000_000, 'description' => 'Thanh toan luong T9/2026', 'counterpart_account' => '0288888', 'counterpart_name' => 'LE VAN C']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('employee', $result['party_type']);
        $this->assertSame(97, $result['confidence']);
        $this->assertTrue(collect($result['reasons'])->contains(fn ($r) => str_contains($r, 'từ khóa lương')));
    }

    /** Rule bổ sung 4 — không khớp số TK, nhưng có tên đối tượng + STK + chiều tiền + nội dung rõ (tên khớp nhân viên) => vẫn phải đưa gợi ý, không để "chưa phân loại". */
    public function test_employee_name_fallback_gives_suggestion_without_account_match(): void
    {
        Employee::create(['code' => 'NV-011', 'name' => 'Pham Thi D', 'status' => 'active', 'base_salary' => 9_000_000, 'bank_account_no' => '0399999', 'created_by' => $this->user->id]);

        // Số TK đối ứng KHÔNG khớp bank_account_no đã đăng ký của nhân viên (vd nhân viên nhận qua TK khác)
        $tx = $this->tx(['debit' => 9_000_000, 'description' => 'CT luong thang 9', 'counterpart_account' => '0777777', 'counterpart_name' => 'PHAM THI D']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('employee', $result['party_type']);
        $this->assertSame('out_salary', CashFlowCategory::find($result['category_id'])->code);
        $this->assertGreaterThanOrEqual(60, $result['confidence']);
    }

    /** Rule bổ sung 4 — tên đối ứng khớp tên NCC dù không khớp số TK, chiều tiền ra => vẫn phải gợi ý. */
    public function test_supplier_name_fallback_gives_suggestion_without_account_match(): void
    {
        Supplier::create(['code' => 'NCC-0002', 'name' => 'Cong Ty TNHH ABC', 'is_active' => true]);

        $tx = $this->tx(['debit' => 3_000_000, 'description' => 'Thanh toan don hang', 'counterpart_account' => '0666666', 'counterpart_name' => 'Cong Ty TNHH ABC']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('supplier', $result['party_type']);
        $this->assertSame('out_supplier_payment', CashFlowCategory::find($result['category_id'])->code);
    }

    /** Rule bổ sung 4 — tên đối ứng khớp tên khách hàng dù không khớp số TK, chiều tiền vào => vẫn phải gợi ý. */
    public function test_customer_name_fallback_gives_suggestion_without_account_match(): void
    {
        Customer::create(['code' => 'KH-0002', 'name' => 'Cong Ty XNK Viet', 'is_active' => true]);

        $tx = $this->tx(['credit' => 15_000_000, 'description' => 'Chuyen tien hang', 'counterpart_account' => '0555555', 'counterpart_name' => 'Cong Ty XNK Viet']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('customer', $result['party_type']);
        $this->assertSame('in_customer_payment', CashFlowCategory::find($result['category_id'])->code);
    }

    /**
     * Priority mới: Trả lương > Thanh toán NCC > Thu khách hàng > Chuyển khoản nội bộ.
     * Case thực tế: TK cá nhân của Giám đốc (là nhân viên) đồng thời cũng được đăng ký làm TK nội bộ
     * công ty (dữ liệu lẫn) — trước đây bị đề xuất nhầm "Chuyển khoản nội bộ" dù nội dung là trả lương.
     */
    public function test_employee_salary_wins_over_internal_transfer_when_account_registered_as_both(): void
    {
        $account = '0912345678';
        Employee::create([
            'code' => 'GD-001', 'name' => 'Le Van Viet', 'status' => 'active',
            'base_salary' => 50_000_000, 'bank_account_no' => $account, 'position' => 'Giám đốc',
            'created_by' => $this->user->id,
        ]);
        InternalBankAccount::create(['name' => 'TK ca nhan GD (dung chung)', 'account_number' => $account, 'bank_name' => 'VCB', 'is_active' => true]);

        $tx = $this->tx(['debit' => 50_000_000, 'description' => 'ADCARE thanh toan luong T5', 'counterpart_account' => $account, 'counterpart_name' => 'LE VAN VIET']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('employee', $result['party_type']);
        $this->assertSame('out_salary', CashFlowCategory::find($result['category_id'])->code);
        $this->assertNotSame('out_internal_transfer', CashFlowCategory::find($result['category_id'])->code);
        $this->assertGreaterThanOrEqual(95, $result['confidence']);
    }

    /** Cùng logic ưu tiên cho NCC: TK NCC dù trùng danh sách TK nội bộ vẫn phải ưu tiên "Thanh toán NCC". */
    public function test_supplier_payment_wins_over_internal_transfer_when_account_registered_as_both(): void
    {
        $account = '19029999';
        $supplier = Supplier::create(['code' => 'NCC-0099', 'name' => 'Cong Ty Vat Tu', 'is_active' => true]);
        SupplierBankAccount::create([
            'supplier_id' => $supplier->id, 'bank_name' => 'MB Bank',
            'account_number' => $account, 'normalized_account_number' => $account, 'is_active' => true,
        ]);
        InternalBankAccount::create(['name' => 'TK nham lan', 'account_number' => $account, 'bank_name' => 'MB Bank', 'is_active' => true]);

        $tx = $this->tx(['debit' => 2_000_000, 'counterpart_account' => $account, 'counterpart_name' => 'CONG TY VAT TU']);

        $result = $this->svc->suggest($tx);

        $this->assertSame('supplier', $result['party_type']);
        $this->assertSame('out_supplier_payment', CashFlowCategory::find($result['category_id'])->code);
    }

    /** Chuyển khoản nội bộ chỉ còn là fallback cuối cùng — vẫn hoạt động đúng khi KHÔNG có bằng chứng lương/NCC/KH nào khác. */
    public function test_internal_transfer_still_applies_when_no_other_evidence_matches(): void
    {
        InternalBankAccount::create(['name' => 'TK Techcombank công ty 2', 'account_number' => '6666666', 'bank_name' => 'Techcombank', 'is_active' => true]);

        $tx = $this->tx(['debit' => 1_000_000, 'counterpart_account' => '6666666', 'counterpart_bank' => 'Techcombank']);

        $result = $this->svc->suggest($tx);

        $this->assertTrue($result['suggestion']);
        $this->assertSame('bank', $result['party_type']);
        $this->assertSame('out_internal_transfer', CashFlowCategory::find($result['category_id'])->code);
    }

    /** DEV TASK Part 3 — Case 1: Giám đốc nhận lương, có từ khóa lương => out_salary, KHÔNG phải out_internal_transfer. */
    public function test_part3_case1_director_salary_with_keyword_suggests_salary_not_internal(): void
    {
        $account = '10689986341';
        Employee::create(['code' => 'GD-002', 'name' => 'Le Van Viet', 'status' => 'active', 'base_salary' => 50_000_000, 'bank_account_no' => $account, 'position' => 'Giám đốc', 'created_by' => $this->user->id]);
        InternalBankAccount::create(['name' => 'TK ca nhan GD', 'account_number' => $account, 'bank_name' => 'VCB', 'is_active' => true]);

        $tx = $this->tx(['debit' => 50_000_000, 'description' => 'ADCARE thanh toan luong T5', 'counterpart_account' => $account, 'counterpart_name' => 'LE VAN VIET']);

        $result = $this->svc->suggest($tx);

        $this->assertSame('out_salary', CashFlowCategory::find($result['category_id'])->code);
        $this->assertNotSame('out_internal_transfer', CashFlowCategory::find($result['category_id'])->code);
    }

    /** DEV TASK Part 3 — Case 2: Giám đốc nhận tiền khác, KHÔNG có từ khóa lương => KHÔNG được gán out_salary. */
    public function test_part3_case2_director_transfer_without_keyword_does_not_suggest_salary(): void
    {
        $account = '10689986341';
        Employee::create(['code' => 'GD-003', 'name' => 'Le Van Viet', 'status' => 'active', 'base_salary' => 50_000_000, 'bank_account_no' => $account, 'position' => 'Giám đốc', 'created_by' => $this->user->id]);

        $tx = $this->tx(['debit' => 20_000_000, 'description' => 'Chuyen khoan', 'counterpart_account' => $account, 'counterpart_name' => 'LE VAN VIET']);

        $result = $this->svc->suggest($tx);

        $category = $result['category_id'] ? CashFlowCategory::find($result['category_id'])->code : null;
        $this->assertNotSame('out_salary', $category);
    }

    /** DEV TASK Part 3 — Case 3: Nhân viên nhận tạm ứng công tác phí, KHÔNG có từ khóa lương => không tự nhận Trả lương. */
    public function test_part3_case3_employee_advance_without_keyword_does_not_suggest_salary(): void
    {
        Employee::create(['code' => 'NV-030', 'name' => 'Nguyen Van A', 'status' => 'active', 'base_salary' => 10_000_000, 'bank_account_no' => '0933444555', 'created_by' => $this->user->id]);

        $tx = $this->tx(['debit' => 2_000_000, 'description' => 'Tam ung cong tac phi', 'counterpart_account' => '0933444555', 'counterpart_name' => 'NGUYEN VAN A']);

        $result = $this->svc->suggest($tx);

        $category = $result['category_id'] ? CashFlowCategory::find($result['category_id'])->code : null;
        $this->assertNotSame('out_salary', $category);
    }

    /** Không tạo/tác động Journal Entry hay bút toán khi confirm gợi ý — chỉ ghi field dòng tiền quản trị. */
    public function test_confirming_suggestion_does_not_touch_journal_entries(): void
    {
        $tx = $this->tx(['debit' => 2_000_000, 'counterpart_account' => '55550000']);
        $category = CashFlowCategory::where('code', 'out_office_expense')->firstOrFail();

        $this->post(route('accounting.bank-accounts.transactions.classify', [$this->bankAccount->id, $tx->id]), [
            'cash_flow_category_id' => $category->id,
        ])->assertRedirect();

        $tx->refresh();
        $this->assertNull($tx->journal_entry_id);
        $this->assertSame(0, \App\Models\JournalEntry::count());
    }
}
