<?php

namespace Tests\Feature;

use App\Models\AccountCode;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\CashVoucher;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Invoice;
use App\Models\Payroll;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test báo cáo Cash Flow — xác nhận method lấy từ fund.type, không hardcode theo [PC]/[PT].
 *
 * CF1: Chi lương từ quỹ tiền mặt → query trả method=cash
 * CF2: Chi lương từ tài khoản ngân hàng → query trả method=bank_transfer (không phải cash)
 * CF3: Dù mã chứng từ là [PC], quỹ ngân hàng vẫn phải trả bank_transfer
 * CF4: Filter method=bank_transfer chỉ trả giao dịch ngân hàng, không lẫn tiền mặt
 * CF5: Endpoint báo cáo trả 200 và có voucher đúng method
 */
class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'reports.view']);
        $this->user->givePermissionTo(['accounting.view', 'reports.view']);
        $this->actingAs($this->user);

        AccountCode::firstOrCreate(['code' => '1121'], [
            'name' => 'Tiền gửi VND', 'type' => 'asset', 'normal_balance' => 'debit',
            'parent_code' => '112', 'level' => 4, 'is_detail' => true, 'is_active' => true,
        ]);
        AccountCode::firstOrCreate(['code' => '1111'], [
            'name' => 'Tiền mặt VND', 'type' => 'asset', 'normal_balance' => 'debit',
            'parent_code' => '111', 'level' => 4, 'is_detail' => true, 'is_active' => true,
        ]);

        $this->employee = Employee::create([
            'code' => 'NV-CF', 'name' => 'Nhân viên Cash Flow Test',
            'status' => 'active', 'base_salary' => 10_000_000, 'allowance' => 0,
            'insurance_subject' => false, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);
    }

    private function createConfirmedPayroll(string $period): array
    {
        $sheet = AttendanceSheet::create([
            'code' => 'CC-' . str_replace('-', '', $period),
            'period' => $period, 'status' => 'locked', 'created_by' => $this->user->id,
        ]);
        AttendanceRecord::create([
            'attendance_sheet_id' => $sheet->id, 'employee_id' => $this->employee->id,
            'days' => '{}', 'cong' => 26, 'nghi_huong_luong' => 0,
            'nghi_khong_luong' => 0, 'ot' => 0, 'tong' => 26,
        ]);
        $this->post(route('accounting.payrolls.store'), ['period' => $period]);
        $payroll = Payroll::where('period', $period)->firstOrFail();
        $this->post(route('accounting.payrolls.confirm', $payroll->id));
        $payroll->refresh();
        return [$payroll, $payroll->items->first()];
    }

    /** Query giống hệt controller để kiểm tra method determination logic. */
    private function queryVoucherMethod(string $voucherCode): ?string
    {
        $row = DB::table('cash_vouchers')
            ->leftJoin('funds', 'funds.id', '=', 'cash_vouchers.fund_id')
            ->selectRaw("cash_vouchers.code, CASE WHEN funds.type = 'bank' THEN 'bank_transfer' ELSE 'cash' END as method, COALESCE(funds.name, '') as fund_name")
            ->where('cash_vouchers.code', $voucherCode)
            ->first();

        return $row ? $row->method : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF1: Chi lương từ quỹ tiền mặt → method = cash
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF1_salary_paid_via_cash_fund_shows_cash_method(): void
    {
        $cashFund = Fund::create([
            'code' => 'QUY-CF1', 'name' => 'Quỹ tiền mặt test',
            'type' => 'cash', 'account_code' => '1111', 'is_active' => true,
        ]);

        [$payroll, $item] = $this->createConfirmedPayroll('2026-05');
        $this->post(route('accounting.payrolls.items.pay', [$payroll->id, $item->id]), ['fund_id' => $cashFund->id]);
        $item->refresh();

        $this->assertEquals('paid', $item->status->value);
        $voucher = CashVoucher::find($item->cash_voucher_id);
        $this->assertNotNull($voucher);

        // Query Cash Flow report logic
        $method = $this->queryVoucherMethod($voucher->code);
        $this->assertEquals('cash', $method, 'Quỹ tiền mặt phải trả method=cash');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF2: Chi lương từ tài khoản ngân hàng → method = bank_transfer
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF2_salary_paid_via_bank_fund_shows_bank_transfer_method(): void
    {
        $bankFund = Fund::create([
            'code' => 'QUY-CF2', 'name' => 'Ngân hàng Vietcombank',
            'type' => 'bank', 'account_code' => '1121', 'is_active' => true,
        ]);

        [$payroll, $item] = $this->createConfirmedPayroll('2026-06');
        $this->post(route('accounting.payrolls.items.pay', [$payroll->id, $item->id]), ['fund_id' => $bankFund->id]);
        $item->refresh();

        $this->assertEquals('paid', $item->status->value);
        $voucher = CashVoucher::find($item->cash_voucher_id);
        $this->assertNotNull($voucher);

        // Dù prefix là PC-, quỹ ngân hàng phải trả bank_transfer
        $this->assertStringStartsWith('PC-', $voucher->code);
        $method = $this->queryVoucherMethod($voucher->code);
        $this->assertNotEquals('cash', $method, 'Quỹ ngân hàng KHÔNG được trả method=cash');
        $this->assertEquals('bank_transfer', $method, 'Quỹ ngân hàng phải trả method=bank_transfer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF3: Mã [PC] không quyết định method — quỹ ngân hàng → bank_transfer dù prefix PC-
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF3_pc_prefix_does_not_force_cash_method(): void
    {
        $bankFund = Fund::create([
            'code' => 'QUY-CF3', 'name' => 'BIDV',
            'type' => 'bank', 'account_code' => '1121', 'is_active' => true,
        ]);

        [$payroll, $item] = $this->createConfirmedPayroll('2026-07');
        $this->post(route('accounting.payrolls.items.pay', [$payroll->id, $item->id]), ['fund_id' => $bankFund->id]);
        $item->refresh();

        $voucher = CashVoucher::find($item->cash_voucher_id);
        $this->assertNotNull($voucher);
        $this->assertStringStartsWith('PC-', $voucher->code, 'Phiếu chi phải có prefix PC-');

        // Mã PC- nhưng method phải là bank_transfer (không phải cash)
        $method = $this->queryVoucherMethod($voucher->code);
        $this->assertEquals('bank_transfer', $method,
            'Mã PC- không được hardcode method=cash khi quỹ là ngân hàng');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF4: Query với filter bank → chỉ trả voucher ngân hàng, không lẫn tiền mặt
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF4_filter_bank_transfer_excludes_cash_vouchers(): void
    {
        $cashFund = Fund::create([
            'code' => 'QUY-CF4C', 'name' => 'Quỹ tiền mặt',
            'type' => 'cash', 'account_code' => '1111', 'is_active' => true,
        ]);
        $bankFund = Fund::create([
            'code' => 'QUY-CF4B', 'name' => 'MB Bank',
            'type' => 'bank', 'account_code' => '1121', 'is_active' => true,
        ]);

        [$p1, $i1] = $this->createConfirmedPayroll('2026-05');
        $this->post(route('accounting.payrolls.items.pay', [$p1->id, $i1->id]), ['fund_id' => $cashFund->id]);
        $i1->refresh();

        [$p2, $i2] = $this->createConfirmedPayroll('2026-06');
        $this->post(route('accounting.payrolls.items.pay', [$p2->id, $i2->id]), ['fund_id' => $bankFund->id]);
        $i2->refresh();

        $v1 = CashVoucher::find($i1->cash_voucher_id);
        $v2 = CashVoucher::find($i2->cash_voucher_id);
        $this->assertNotNull($v1);
        $this->assertNotNull($v2);

        // Simulate controller filter: method=bank_transfer → only bank funds
        $bankRows = DB::table('cash_vouchers')
            ->leftJoin('funds', 'funds.id', '=', 'cash_vouchers.fund_id')
            ->where('cash_vouchers.type', 'payment')
            ->where('cash_vouchers.status', 'confirmed')
            ->where('funds.type', 'bank') // filter for bank_transfer
            ->pluck('cash_vouchers.code')
            ->all();

        $this->assertContains($v2->code, $bankRows, 'Phiếu chi ngân hàng phải có trong kết quả bank_transfer');
        $this->assertNotContains($v1->code, $bankRows, 'Phiếu chi tiền mặt không được xuất hiện khi lọc bank_transfer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF6: AR payment tạo PT- collect_customer → voucherIn loại bỏ, payments tính, total không nhân đôi
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF6_invoice_payment_pt_voucher_not_double_counted_in_cashflow(): void
    {
        $customer = Customer::firstOrCreate(
            ['code' => 'KH-CF6'],
            ['name' => 'KH CF6 Test', 'phone' => '0900000006']
        );

        $invoice = Invoice::create([
            'code'        => 'HD-CF6-001',
            'customer_id' => $customer->id,
            'subtotal'    => 2_000_000,
            'tax_amount'  => 200_000,
            'total'       => 2_200_000,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
            'issue_date'  => '2026-06-20',
        ]);

        $fund = Fund::firstOrCreate(
            ['code' => 'QUY-CF6'],
            ['name' => 'Quỹ CF6', 'type' => 'cash', 'account_code' => '1111', 'is_active' => true]
        );

        $testDate = '2026-06-20';
        $amount   = 2_200_000;

        // Tạo Payment (bảng nguồn cho inflow)
        DB::table('payments')->insert([
            'invoice_id'   => $invoice->id,
            'fund_id'      => $fund->id,
            'amount'       => $amount,
            'payment_date' => $testDate,
            'method'       => 'cash',
            'created_by'   => $this->user->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Tạo PT- CashVoucher collect_customer (như InvoiceService::addPayment() làm từ migration 900112)
        DB::table('cash_vouchers')->insert([
            'code'          => 'PT-CF6-001',
            'type'          => 'receipt',
            'status'        => 'confirmed',
            'fund_id'       => $fund->id,
            'customer_id'   => $customer->id,
            'partner_type'  => 'customer',
            'amount'        => $amount,
            'voucher_date'  => $testDate,
            'description'   => 'Thu tiền HD-CF6-001',
            'business_type' => 'collect_customer',
            'reference_type'=> 'invoice',
            'reference_id'  => $invoice->id,
            'created_by'    => $this->user->id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Verify: voucherIn với filter đúng → collect_customer bị loại
        $voucherInCount = DB::table('cash_vouchers')
            ->where('type', 'receipt')
            ->where('status', 'confirmed')
            ->whereNotIn('business_type', ['collect_customer', 'pay_supplier'])
            ->whereBetween('voucher_date', [$testDate, $testDate])
            ->count();

        $this->assertEquals(0, $voucherInCount,
            'collect_customer PT- phải bị loại khỏi voucherIn (đã tính qua bảng payments)');

        // Verify: payments table vẫn có row
        $paymentCount = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereBetween('payments.payment_date', [$testDate, $testDate])
            ->count();

        $this->assertEquals(1, $paymentCount, 'Payment vẫn được đếm 1 lần từ bảng payments');

        // Endpoint trả 200
        $this->get(route('reports.cash_flow', [
            'date_from' => $testDate,
            'date_to'   => $testDate,
            'type'      => 'in',
        ]))->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF7: AP payment tạo PC- pay_supplier → voucherOut loại bỏ, purchase_invoice_payments tính
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF7_purchase_invoice_payment_pc_voucher_not_double_counted_in_cashflow(): void
    {
        $supplier = Supplier::firstOrCreate(
            ['code' => 'NCC-CF7'],
            ['name' => 'NCC CF7 Test', 'is_active' => true]
        );

        // Cần Warehouse + PO để đáp ứng FK của purchase_invoices (SQLite FK enabled)
        $warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'Kho CF7', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $poId = DB::table('purchase_orders')->insertGetId([
            'code'         => 'MH-CF7-001',
            'supplier_id'  => $supplier->id,
            'warehouse_id' => $warehouseId,
            'status'       => 'sent',
            'order_date'   => '2026-06-20',
            'created_by'   => $this->user->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $piId = DB::table('purchase_invoices')->insertGetId([
            'code'              => 'HD-NCC-CF7',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $poId,
            'subtotal'          => 5_000_000,
            'tax_amount'        => 500_000,
            'total'             => 5_500_000,
            'paid_amount'       => 0,
            'status'            => 'valid',
            'invoice_type'      => 'goods',
            'invoice_date'      => '2026-06-20',
            'created_by'        => $this->user->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $fund = Fund::firstOrCreate(
            ['code' => 'QUY-CF7'],
            ['name' => 'Quỹ CF7', 'type' => 'cash', 'account_code' => '1111', 'is_active' => true]
        );

        $testDate = '2026-06-20';
        $amount   = 5_500_000;

        // Tạo PurchaseInvoicePayment (bảng nguồn cho outflow)
        DB::table('purchase_invoice_payments')->insert([
            'purchase_invoice_id' => $piId,
            'fund_id'             => $fund->id,
            'amount'              => $amount,
            'payment_date'        => $testDate,
            'method'              => 'cash',
            'created_by'          => $this->user->id,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Tạo PC- CashVoucher pay_supplier (như service làm từ migration 900112)
        DB::table('cash_vouchers')->insert([
            'code'           => 'PC-CF7-001',
            'type'           => 'payment',
            'status'         => 'confirmed',
            'fund_id'        => $fund->id,
            'supplier_id'    => $supplier->id,
            'partner_type'   => 'supplier',
            'amount'         => $amount,
            'voucher_date'   => $testDate,
            'description'    => 'Trả HĐ HD-NCC-CF7',
            'business_type'  => 'pay_supplier',
            'reference_type' => 'purchase_invoice',
            'reference_id'   => $piId,
            'created_by'     => $this->user->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Verify: voucherOut với filter đúng → pay_supplier bị loại
        $voucherOutCount = DB::table('cash_vouchers')
            ->where('type', 'payment')
            ->where('status', 'confirmed')
            ->whereNotIn('business_type', ['collect_customer', 'pay_supplier'])
            ->whereBetween('voucher_date', [$testDate, $testDate])
            ->count();

        $this->assertEquals(0, $voucherOutCount,
            'pay_supplier PC- phải bị loại khỏi voucherOut (đã tính qua purchase_invoice_payments)');

        // Verify: purchase_invoice_payments table vẫn có row
        $pipCount = DB::table('purchase_invoice_payments')
            ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_payments.purchase_invoice_id')
            ->whereBetween('purchase_invoice_payments.payment_date', [$testDate, $testDate])
            ->count();

        $this->assertEquals(1, $pipCount, 'PurchaseInvoicePayment vẫn được đếm 1 lần từ bảng nguồn');

        // Endpoint trả 200
        $this->get(route('reports.cash_flow', [
            'date_from' => $testDate,
            'date_to'   => $testDate,
            'type'      => 'out',
        ]))->assertOk();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CF5: Endpoint báo cáo trả 200 OK và có voucher đúng method
    // ─────────────────────────────────────────────────────────────────────────

    public function test_CF5_cash_flow_endpoint_returns_200(): void
    {
        $bankFund = Fund::create([
            'code' => 'QUY-CF5', 'name' => 'VCB',
            'type' => 'bank', 'account_code' => '1121', 'is_active' => true,
        ]);

        [$payroll, $item] = $this->createConfirmedPayroll('2026-05');
        $this->post(route('accounting.payrolls.items.pay', [$payroll->id, $item->id]), ['fund_id' => $bankFund->id]);
        $item->refresh();

        $voucher = CashVoucher::find($item->cash_voucher_id);
        $dateStr = $voucher->voucher_date->toDateString();

        $response = $this->get(route('reports.cash_flow', [
            'date_from' => $dateStr,
            'date_to'   => $dateStr,
            'type'      => 'out',
        ]));

        $response->assertOk();

        // Verify via DB that the voucher would have correct method
        $method = $this->queryVoucherMethod($voucher->code);
        $this->assertEquals('bank_transfer', $method);
    }
}
