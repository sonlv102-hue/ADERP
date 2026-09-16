<?php

namespace Tests\Feature\Accounting;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Cột "Loại GD" trên màn Giao dịch ngân hàng phải phản ánh phân loại dòng tiền quản trị
 * (cash_flow_category/party) ngay sau khi Admin xác nhận qua CashFlowClassificationService::update(),
 * KHÔNG còn dùng tx_type (đối soát cũ, do BankTransactionMatchingService sở hữu).
 */
class BankTransactionCashFlowClassificationDisplayTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $bankAccount;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);

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
            'transaction_date' => '2026-09-16',
            'description' => 'GD test',
            'debit' => 0, 'credit' => 0,
        ], $extra));
    }

    /** Case 1 — GD tiền ra, nhân viên, nội dung lương, xác nhận "Trả lương" => LOẠI GD = Trả lương (confirmed). */
    public function test_case1_confirming_salary_updates_loai_gd_to_confirmed(): void
    {
        $employee = Employee::create(['code' => 'NV-100', 'name' => 'Vo Van E', 'status' => 'active', 'base_salary' => 10_000_000, 'bank_account_no' => '0811111', 'created_by' => $this->user->id]);
        $category = CashFlowCategory::where('code', 'out_salary')->firstOrFail();

        $tx = $this->tx(['debit' => 10_000_000, 'description' => 'Thanh toan luong T9', 'counterpart_account' => '0811111', 'counterpart_name' => 'VO VAN E']);

        // Trước khi xác nhận: chưa confirmed (unclassified hoặc suggested)
        $this->get(route('accounting.bank-accounts.transactions.index', $this->bankAccount))
            ->assertInertia(fn ($page) => $page->where('transactions.data.0.id', $tx->id)
                ->where('transactions.data.0.cash_flow_classification.status', fn ($s) => $s !== 'confirmed'));

        $this->post(route('accounting.bank-accounts.transactions.classify', [$this->bankAccount->id, $tx->id]), [
            'cash_flow_category_id' => $category->id,
            'party_type' => 'employee',
            'party_id' => $employee->id,
            'party_name' => $employee->name,
        ])->assertRedirect();

        $this->get(route('accounting.bank-accounts.transactions.index', $this->bankAccount))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.id', $tx->id)
                ->where('transactions.data.0.cash_flow_classification.status', 'confirmed')
                ->where('transactions.data.0.cash_flow_classification.category', $category->name)
                ->where('transactions.data.0.cash_flow_classification.party', $employee->name));
    }

    /** Case 2 — GD tiền ra, NCC, xác nhận "Thanh toán NCC" => LOẠI GD = Thanh toán NCC (confirmed). */
    public function test_case2_confirming_supplier_payment_updates_loai_gd_to_confirmed(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-0010', 'name' => 'Cong Ty ABC', 'is_active' => true]);
        SupplierBankAccount::create([
            'supplier_id' => $supplier->id, 'bank_name' => 'MB Bank',
            'account_number' => '19022222', 'normalized_account_number' => '19022222', 'is_active' => true,
        ]);
        $category = CashFlowCategory::where('code', 'out_supplier_payment')->firstOrFail();

        $tx = $this->tx(['debit' => 5_000_000, 'counterpart_account' => '19022222', 'counterpart_name' => 'CONG TY ABC']);

        $this->post(route('accounting.bank-accounts.transactions.classify', [$this->bankAccount->id, $tx->id]), [
            'cash_flow_category_id' => $category->id,
            'party_type' => 'supplier',
            'party_id' => $supplier->id,
            'party_name' => $supplier->name,
        ])->assertRedirect();

        $this->get(route('accounting.bank-accounts.transactions.index', $this->bankAccount))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.cash_flow_classification.status', 'confirmed')
                ->where('transactions.data.0.cash_flow_classification.category', $category->name)
                ->where('transactions.data.0.cash_flow_classification.party', $supplier->name));
    }

    /** Case 3 — Reload trang (gọi lại index() độc lập 2 lần) => trạng thái đã xác nhận không mất, luôn đọc từ DB. */
    public function test_case3_reloading_page_keeps_confirmed_classification(): void
    {
        $category = CashFlowCategory::where('code', 'out_office_expense')->firstOrFail();
        $tx = $this->tx(['debit' => 1_000_000, 'counterpart_account' => '099999']);

        $this->post(route('accounting.bank-accounts.transactions.classify', [$this->bankAccount->id, $tx->id]), [
            'cash_flow_category_id' => $category->id,
            'party_type' => 'other_entity',
            'party_name' => 'Đối tác X',
        ])->assertRedirect();

        foreach (range(1, 2) as $i) {
            $this->get(route('accounting.bank-accounts.transactions.index', $this->bankAccount))
                ->assertInertia(fn ($page) => $page
                    ->where('transactions.data.0.cash_flow_classification.status', 'confirmed')
                    ->where('transactions.data.0.cash_flow_classification.category', $category->name));
        }
    }

    /** Chưa phân loại và không có gợi ý => status unclassified, không lộ tx_type cũ ra field cash_flow_classification. */
    public function test_unclassified_transaction_has_unclassified_status(): void
    {
        $this->tx(['debit' => 500_000, 'counterpart_account' => '000000', 'counterpart_name' => 'NGUOI LA']);

        $this->get(route('accounting.bank-accounts.transactions.index', $this->bankAccount))
            ->assertInertia(fn ($page) => $page
                ->where('transactions.data.0.cash_flow_classification.status', 'unclassified')
                ->where('transactions.data.0.cash_flow_classification.category', null));
    }
}
