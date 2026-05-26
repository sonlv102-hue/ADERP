<?php

namespace Tests\Feature;

use App\Enums\PayrollStatus;
use App\Enums\PayrollItemStatus;
use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Fund;
use App\Models\User;
use App\Models\CashVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user for auth
        $this->user = User::factory()->create([
            'is_active' => true,
            'base_salary' => 10000000,
            'allowance' => 1000000,
        ]);
        
        // Setup permissions
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view']);
        $this->user->givePermissionTo('accounting.view');
        
        $this->actingAs($this->user);

        // Create a fund
        $this->fund = Fund::create([
            'code' => 'QTM-01',
            'name' => 'Quỹ tiền mặt VP',
            'type' => 'cash',
            'opening_balance' => 500000000,
            'is_active' => true,
        ]);
    }

    public function test_can_create_payroll_and_populate_active_users(): void
    {
        $response = $this->post(route('accounting.payrolls.store'), [
            'period' => '2026-05',
            'notes' => 'Bảng lương kiểm thử',
        ]);

        $response->assertRedirect(route('accounting.payrolls.index'));
        $this->assertDatabaseHas('payrolls', [
            'period' => '2026-05',
            'status' => PayrollStatus::Draft->value,
            'total_base_salary' => 10000000.00,
            'total_allowance' => 1000000.00,
            'total_net_salary' => 11000000.00,
        ]);

        $payroll = Payroll::where('period', '2026-05')->first();
        $this->assertCount(1, $payroll->items);
        
        $item = $payroll->items->first();
        $this->assertEquals(10000000, $item->base_salary);
        $this->assertEquals(1000000, $item->allowance);
        $this->assertEquals(11000000, $item->net_salary);
        $this->assertEquals(PayrollItemStatus::Pending->value, $item->status->value);
    }

    public function test_can_update_payroll_item_and_recalculate_totals(): void
    {
        // 1. Create payroll
        $this->post(route('accounting.payrolls.store'), [
            'period' => '2026-05',
        ]);
        
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items->first();

        // 2. Update item
        $response = $this->put(route('accounting.payrolls.items.update', [$payroll->id, $item->id]), [
            'base_salary' => 12000000,
            'allowance' => 1500000,
            'bonus' => 2000000,
            'deductions' => 500000,
        ]);

        $response->assertRedirect();
        
        $item->refresh();
        $this->assertEquals(12000000, $item->base_salary);
        $this->assertEquals(1500000, $item->allowance);
        $this->assertEquals(2000000, $item->bonus);
        $this->assertEquals(500000, $item->deductions);
        $this->assertEquals(15000000, $item->net_salary); // 12 + 1.5 + 2 - 0.5 = 15

        $payroll->refresh();
        $this->assertEquals(12000000, $payroll->total_base_salary);
        $this->assertEquals(1500000, $payroll->total_allowance);
        $this->assertEquals(2000000, $payroll->total_bonus);
        $this->assertEquals(500000, $payroll->total_deductions);
        $this->assertEquals(15000000, $payroll->total_net_salary);
    }

    public function test_can_confirm_payroll_and_pay_employee(): void
    {
        // 1. Create payroll
        $this->post(route('accounting.payrolls.store'), [
            'period' => '2026-05',
        ]);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items->first();

        // 2. Confirm payroll
        $response = $this->post(route('accounting.payrolls.confirm', $payroll->id));
        $response->assertRedirect();
        
        $payroll->refresh();
        $this->assertEquals(PayrollStatus::Confirmed->value, $payroll->status->value);

        // 3. Pay employee
        $payResponse = $this->post(route('accounting.payrolls.items.pay', [$payroll->id, $item->id]), [
            'fund_id' => $this->fund->id,
        ]);
        $payResponse->assertRedirect();

        $item->refresh();
        $this->assertEquals(PayrollItemStatus::Paid->value, $item->status->value);
        $this->assertNotNull($item->paid_at);
        $this->assertNotNull($item->cash_voucher_id);

        // 4. Verify Cash Voucher was created & confirmed
        $voucher = CashVoucher::find($item->cash_voucher_id);
        $this->assertNotNull($voucher);
        $this->assertEquals('payment', $voucher->type->value);
        $this->assertEquals(CashVoucherStatus::Confirmed->value, $voucher->status->value);
        $this->assertEquals($item->net_salary, $voucher->amount);
        $this->assertEquals($this->fund->id, $voucher->fund_id);

        // 5. Verify entire payroll is marked as paid
        $payroll->refresh();
        $this->assertEquals(PayrollStatus::Paid->value, $payroll->status->value);
    }
}
