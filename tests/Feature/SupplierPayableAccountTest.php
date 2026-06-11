<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\Supplier;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiểm tra logic payable_account_code trên Supplier:
 *   TC1 — Supplier có payable_account_code = 3311 → JE dùng 3311
 *   TC2 — Supplier có payable_account_code = 3312 → JE dùng 3312
 *   TC3 — Supplier chưa cấu hình (null) → getPayableAccount() ném RuntimeException
 *   TC4 — payable_account_code = '331' (TK cha) → không pass validation (is_detail=false)
 *   TC5 — AccountingService.validateLines() từ chối dòng dùng TK 331 (cha)
 */
class SupplierPayableAccountTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '331',  'name' => 'Phải trả cho người bán', 'type' => 'liability', 'normal_balance' => 'credit', 'balance_type' => 'both',   'level' => 1, 'is_detail' => false, 'is_active' => true],
            ['code' => '3311', 'name' => 'NCC trong nước',         'type' => 'liability', 'normal_balance' => 'credit', 'balance_type' => 'both',   'level' => 2, 'is_detail' => true,  'is_active' => true],
            ['code' => '3312', 'name' => 'NCC nước ngoài',         'type' => 'liability', 'normal_balance' => 'credit', 'balance_type' => 'both',   'level' => 2, 'is_detail' => true,  'is_active' => true],
            ['code' => '1121', 'name' => 'Tiền gửi VND',           'type' => 'asset',     'normal_balance' => 'debit',  'balance_type' => 'normal', 'level' => 2, 'is_detail' => true,  'is_active' => true],
        ];
        foreach ($accounts as $a) {
            // updateOrCreate để override giá trị is_detail đúng, kể cả khi record đã tồn tại từ seeder khác
            AccountCode::updateOrCreate(['code' => $a['code']], $a);
        }
    }

    /** TC1: Supplier 3311 → getPayableAccount() trả về '3311' */
    public function test_supplier_with_3311_returns_correct_account(): void
    {
        $this->seedAccounts();
        $supplier = Supplier::create(['code' => 'NCC-0001', 'name' => 'NCC Test 1', 'payable_account_code' => '3311', 'is_active' => true]);

        $this->assertSame('3311', $supplier->getPayableAccount());
    }

    /** TC2: Supplier 3312 → getPayableAccount() trả về '3312' */
    public function test_supplier_with_3312_returns_correct_account(): void
    {
        $this->seedAccounts();

        $supplier = Supplier::create(['code' => 'NCC-0002', 'name' => 'NCC Test 2', 'payable_account_code' => '3312', 'is_active' => true]);

        $this->assertSame('3312', $supplier->getPayableAccount());
    }

    /** TC3: Supplier chưa cấu hình → ném RuntimeException với message rõ ràng */
    public function test_supplier_without_payable_account_throws(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-0003', 'name' => 'NCC Test 3', 'payable_account_code' => null, 'is_active' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/chưa cấu hình tài khoản công nợ/i');

        $supplier->getPayableAccount();
    }

    /**
     * TC4: account_codes['331'] phải có is_detail=false,
     * và query with is_detail=true không trả về '331'
     */
    public function test_parent_account_331_is_not_detail(): void
    {
        $this->seedAccounts();

        $account = AccountCode::where('code', '331')->first();
        $this->assertNotNull($account, 'TK 331 phải được seed');
        $this->assertFalse((bool) $account->is_detail, 'TK 331 phải là tài khoản tổng hợp (is_detail=false)');

        // Xác nhận query dùng trong SupplierController validation không trả về '331'
        $found = AccountCode::where('code', '331')->where('is_detail', true)->exists();
        $this->assertFalse($found, 'Query is_detail=true không được tìm thấy TK 331');
    }

    /** TC5: AccountingService::validateLines() từ chối dòng dùng TK 331 (cha) */
    public function test_accounting_service_rejects_parent_331_in_journal_lines(): void
    {
        $this->seedAccounts();

        $service = app(AccountingService::class);
        $method  = new \ReflectionMethod($service, 'validateLines');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tổng hợp/i');

        $method->invoke($service, [
            ['account' => '331',  'debit' => 1000, 'credit' => 0,    'description' => 'Dr 331'],
            ['account' => '1121', 'debit' => 0,    'credit' => 1000, 'description' => 'Cr 1121'],
        ]);
    }
}
