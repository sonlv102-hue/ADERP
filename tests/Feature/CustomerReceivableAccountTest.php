<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\Customer;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiểm tra logic receivable_account_code trên Customer:
 *   TC1 — Customer có receivable_account_code = 1311 → JE dùng 1311
 *   TC2 — Customer có receivable_account_code = 1312 → JE dùng 1312
 *   TC3 — Customer chưa cấu hình (null) → getReceivableAccount() ném RuntimeException
 *   TC4 — receivable_account_code = '131' (TK cha) → không pass validation (is_detail=false)
 *   TC5 — AccountingService.validateLines() từ chối dòng dùng TK 131 (cha)
 */
class CustomerReceivableAccountTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '131',  'name' => 'Phải thu khách hàng',     'type' => 'asset', 'normal_balance' => 'debit', 'balance_type' => 'both',   'level' => 1, 'is_detail' => false, 'is_active' => true],
            ['code' => '1311', 'name' => 'Khách hàng bán hàng - DN', 'type' => 'asset', 'normal_balance' => 'debit', 'balance_type' => 'both',   'level' => 2, 'is_detail' => true,  'is_active' => true],
            ['code' => '1312', 'name' => 'Khách hàng bán hàng - NN', 'type' => 'asset', 'normal_balance' => 'debit', 'balance_type' => 'both',   'level' => 2, 'is_detail' => true,  'is_active' => true],
            ['code' => '1121', 'name' => 'Tiền gửi VND',             'type' => 'asset', 'normal_balance' => 'debit', 'balance_type' => 'normal', 'level' => 2, 'is_detail' => true,  'is_active' => true],
        ];
        foreach ($accounts as $a) {
            AccountCode::updateOrCreate(['code' => $a['code']], $a);
        }
    }

    /** TC1: Customer 1311 → getReceivableAccount() trả về '1311' */
    public function test_customer_with_1311_returns_correct_account(): void
    {
        $this->seedAccounts();
        $customer = Customer::create(['code' => 'KH-0001', 'name' => 'KH Test 1', 'receivable_account_code' => '1311', 'is_active' => true]);

        $this->assertSame('1311', $customer->getReceivableAccount());
    }

    /** TC2: Customer 1312 → getReceivableAccount() trả về '1312' */
    public function test_customer_with_1312_returns_correct_account(): void
    {
        $this->seedAccounts();
        $customer = Customer::create(['code' => 'KH-0002', 'name' => 'KH Test 2', 'receivable_account_code' => '1312', 'is_active' => true]);

        $this->assertSame('1312', $customer->getReceivableAccount());
    }

    /** TC3: Customer chưa cấu hình → ném RuntimeException với message rõ ràng */
    public function test_customer_without_receivable_account_throws(): void
    {
        $customer = Customer::create(['code' => 'KH-0003', 'name' => 'KH Test 3', 'receivable_account_code' => null, 'is_active' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/chưa cấu hình tài khoản phải thu/i');

        $customer->getReceivableAccount();
    }

    /**
     * TC4: account_codes['131'] phải có is_detail=false,
     * và query with is_detail=true không trả về '131'
     */
    public function test_parent_account_131_is_not_detail(): void
    {
        $this->seedAccounts();

        $account = AccountCode::where('code', '131')->first();
        $this->assertNotNull($account, 'TK 131 phải được seed');
        $this->assertFalse((bool) $account->is_detail, 'TK 131 phải là tài khoản tổng hợp (is_detail=false)');

        // Xác nhận query dùng trong CustomerController validation không trả về '131'
        $found = AccountCode::where('code', '131')->where('is_detail', true)->exists();
        $this->assertFalse($found, 'Query is_detail=true không được tìm thấy TK 131');
    }

    /** TC5: AccountingService::validateLines() từ chối dòng dùng TK 131 (cha) */
    public function test_accounting_service_rejects_parent_131_in_journal_lines(): void
    {
        $this->seedAccounts();

        $service = app(AccountingService::class);
        $method  = new \ReflectionMethod($service, 'validateLines');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tổng hợp/i');

        $method->invoke($service, [
            ['account' => '131',  'debit' => 1000, 'credit' => 0,    'description' => 'Dr 131'],
            ['account' => '1121', 'debit' => 0,    'credit' => 1000, 'description' => 'Cr 1121'],
        ]);
    }
}
