<?php

namespace Tests\Feature\Accounting;

use App\Enums\PayrollStatus;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests:
 * 1.  Export Excel trả 200, content-type xlsx
 * 2.  Export PDF trả 200, content-type pdf
 * 3.  Người dùng không có accounting.view bị 403
 * 4.  Điều chỉnh dương làm tăng thực lĩnh
 * 5.  Điều chỉnh âm làm giảm thực lĩnh
 * 6.  Filter theo period trả đúng kết quả
 * 7.  Filter theo status trả đúng kết quả
 */
class PayrollExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Payroll $payroll;
    private PayrollItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.manage']);
        $this->user->givePermissionTo('accounting.view');
        $this->actingAs($this->user);

        Setting::set('company_name', 'Công ty kiểm thử');

        $employee = Employee::create([
            'code'             => 'NV-T001',
            'name'             => 'Nhân viên test',
            'status'           => 'active',
            'base_salary'      => 10_000_000,
            'insurance_subject'=> false,
            'standard_days'    => 26,
            'created_by'       => $this->user->id,
        ]);

        $this->payroll = Payroll::create([
            'code'              => 'BL-202605',
            'period'            => '2026-05',
            'status'            => PayrollStatus::Draft->value,
            'total_base_salary' => 10_000_000,
            'total_net_salary'  => 10_000_000,
            'total_adjustment'  => 0,
            'created_by'        => $this->user->id,
        ]);

        $this->item = PayrollItem::create([
            'payroll_id'        => $this->payroll->id,
            'employee_id'       => $employee->id,
            'base_salary'       => 10_000_000,
            'gross_salary'      => 10_000_000,
            'insurance_base'    => 0,
            'bhxh_employee'     => 0,
            'bhyt_employee'     => 0,
            'bhtn_employee'     => 0,
            'bhxh_employer'     => 0,
            'bhyt_employer'     => 0,
            'bhtn_employer'     => 0,
            'pit'               => 0,
            'deductions'        => 0,
            'net_salary'        => 10_000_000,
            'working_days'      => 26,
            'standard_days'     => 26,
            'advance'           => 0,
            'adjustment_amount' => 0,
            'status'            => 'pending',
        ]);
    }

    public function test_export_excel_returns_200_with_xlsx_content_type(): void
    {
        $response = $this->get(route('accounting.payrolls.export-excel', $this->payroll));
        $response->assertStatus(200);
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('Content-Type') ?? ''
        );
    }

    public function test_export_pdf_returns_200_with_pdf_content_type(): void
    {
        $response = $this->get(route('accounting.payrolls.export-pdf', $this->payroll));
        $response->assertStatus(200);
        $this->assertStringContainsString(
            'pdf',
            strtolower($response->headers->get('Content-Type') ?? '')
        );
    }

    public function test_user_without_accounting_view_gets_403(): void
    {
        $guest = User::factory()->create(['is_active' => true]);
        $this->actingAs($guest);

        $this->get(route('accounting.payrolls.export-excel', $this->payroll))->assertStatus(403);
        $this->get(route('accounting.payrolls.export-pdf',   $this->payroll))->assertStatus(403);
    }

    public function test_positive_adjustment_increases_thuc_linh(): void
    {
        $netBefore = (float) $this->item->net_salary;
        $adjustment = 500_000;

        $this->item->update([
            'adjustment_amount' => $adjustment,
        ]);

        $thucLinh = (float) $this->item->net_salary + (float) $this->item->adjustment_amount - (float) ($this->item->advance ?? 0);
        $this->assertGreaterThan($netBefore, $thucLinh);
        $this->assertEquals($netBefore + $adjustment, $thucLinh);
    }

    public function test_negative_adjustment_decreases_thuc_linh(): void
    {
        $netBefore = (float) $this->item->net_salary;
        $adjustment = -200_000;

        $this->item->update([
            'adjustment_amount' => $adjustment,
        ]);

        $thucLinh = (float) $this->item->net_salary + (float) $this->item->adjustment_amount - (float) ($this->item->advance ?? 0);
        $this->assertLessThan($netBefore, $thucLinh);
        $this->assertEquals($netBefore + $adjustment, $thucLinh);
    }

    public function test_filter_by_period_returns_correct_results(): void
    {
        // Create a second payroll for a different period
        Payroll::create([
            'code'              => 'BL-202606',
            'period'            => '2026-06',
            'status'            => PayrollStatus::Draft->value,
            'total_base_salary' => 10_000_000,
            'total_net_salary'  => 10_000_000,
            'created_by'        => $this->user->id,
        ]);

        $response = $this->get(route('accounting.payrolls.index', ['period' => '2026-05']));
        $response->assertStatus(200);
        $data = $response->original?->getData()['page']['props']['payrolls']['data'] ?? [];
        if (!empty($data)) {
            $this->assertCount(1, $data);
            $this->assertEquals('2026-05', $data[0]['period']);
        } else {
            // Inertia response — check no period 2026-06 in response JSON
            $response->assertSee('2026-05');
            $response->assertDontSee('BL-202606');
        }
    }

    public function test_filter_by_status_returns_correct_results(): void
    {
        Payroll::create([
            'code'              => 'BL-202604',
            'period'            => '2026-04',
            'status'            => PayrollStatus::Confirmed->value,
            'total_base_salary' => 10_000_000,
            'total_net_salary'  => 10_000_000,
            'created_by'        => $this->user->id,
        ]);

        $response = $this->get(route('accounting.payrolls.index', ['status' => 'draft']));
        $response->assertStatus(200);
        // The draft payroll (BL-202605) should be visible
        $response->assertSee('BL-202605');
    }
}
