<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PitCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Thu nhập chịu thuế = Tổng lương thực tế (gross_salary) - 1.200.000 (yêu cầu nghiệp vụ mới).
 * Nguồn tính duy nhất: PitCalculatorService::breakdown() / calcPitFromGross().
 *
 * Case 1-3: unit test trực tiếp trên PitCalculatorService::breakdown().
 * Case 4: sync lại sau khi Employee đổi base_salary → taxable_income tính lại theo công thức mới.
 * Case 5: manual PIT override không bị mất khi công thức thay đổi.
 */
class PitTaxableIncomeTest extends TestCase
{
    use RefreshDatabase;

    private PitCalculatorService $pit;
    private User $user;
    private Employee $employeeA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pit = app(PitCalculatorService::class);

        $this->user = User::factory()->create(['is_active' => true]);
        Permission::firstOrCreate(['name' => 'accounting.view']);
        $this->user->givePermissionTo(['accounting.view']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->user->assignRole($adminRole);
        $this->actingAs($this->user);

        $this->employeeA = Employee::create([
            'code' => 'NV-A', 'name' => 'Nhân viên A', 'status' => 'active',
            'base_salary' => 10_000_000, 'insurance_subject' => false, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $sheet = AttendanceSheet::create([
            'code' => 'CC-202605', 'period' => '2026-05', 'status' => 'locked', 'created_by' => $this->user->id,
        ]);
        AttendanceRecord::create([
            'attendance_sheet_id' => $sheet->id, 'employee_id' => $this->employeeA->id,
            'days' => '{}', 'cong' => 26, 'nghi_huong_luong' => 0, 'nghi_khong_luong' => 0, 'ot' => 0, 'tong' => 26,
        ]);
    }

    /** Case 1: Tổng lương thực tế = 10.000.000 → Thu nhập chịu thuế = 8.800.000 */
    public function test_case1_taxable_income_10m_gross(): void
    {
        $bd = $this->pit->breakdown(baseSalary: 10_000_000);
        $this->assertEquals(10_000_000, $bd['gross_salary']);
        $this->assertEquals(8_800_000, $bd['taxable_income']);
    }

    /** Case 2: Tổng lương thực tế = 1.200.000 → Thu nhập chịu thuế = 0 */
    public function test_case2_taxable_income_equals_allowance_is_zero(): void
    {
        $bd = $this->pit->breakdown(baseSalary: 1_200_000);
        $this->assertEquals(0, $bd['taxable_income']);
    }

    /** Case 3: Tổng lương thực tế = 800.000 (< 1.200.000) → Thu nhập chịu thuế = 0, không âm */
    public function test_case3_taxable_income_below_allowance_floors_at_zero(): void
    {
        $bd = $this->pit->breakdown(baseSalary: 800_000);
        $this->assertEquals(0, $bd['taxable_income']);
        $this->assertGreaterThanOrEqual(0, $bd['taxable_for_pit']);
        $this->assertEquals(0, $bd['pit'], 'Lương quá thấp thì không phát sinh thuế TNCN');
    }

    /** Regression: taxable_for_pit dùng taxable_income làm đầu vào, không trừ 1.200.000 hai lần */
    public function test_taxable_for_pit_does_not_double_subtract_allowance(): void
    {
        $bd = $this->pit->breakdown(baseSalary: 20_000_000, dependents: 0, insuranceSubject: false);
        // taxable_income = 20tr - 1.2tr = 18.8tr; taxable_for_pit = 18.8tr - 0(BHXH, vì ko insurance_subject) - 15.5tr = 3.3tr
        $this->assertEquals(18_800_000, $bd['taxable_income']);
        $this->assertEquals(3_300_000, $bd['taxable_for_pit']);
    }

    /** Case 4: sync lại sau khi Employee đổi base_salary → taxable_income tính lại theo công thức mới */
    public function test_case4_taxable_income_recalculated_after_employee_base_salary_sync(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05'])->assertRedirect();
        $payroll = Payroll::where('period', '2026-05')->first();

        $response = $this->get(route('accounting.payrolls.show', $payroll->id));
        $response->assertInertia(fn ($page) => $page->where('items.0.taxable_income', 8_800_000)); // 10tr - 1.2tr

        $this->employeeA->update(['base_salary' => 15_000_000]);
        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();

        $response2 = $this->get(route('accounting.payrolls.show', $payroll->id));
        $response2->assertInertia(fn ($page) => $page->where('items.0.taxable_income', 13_800_000)); // 15tr - 1.2tr
    }

    /** Case 5: manual PIT override không bị mất khi công thức Thu nhập chịu thuế thay đổi */
    public function test_case5_manual_pit_override_survives_formula_change(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items()->first();

        $this->put(route('accounting.payrolls.items.update', [$payroll->id, $item->id]), [
            'base_salary' => $item->base_salary,
            'insurance_subject' => false,
            'pit_override_enabled' => true,
            'pit_override' => 333_000,
            'override_reason' => 'Điều chỉnh theo quyết toán thuế',
        ])->assertRedirect();

        $item->refresh();
        $this->assertTrue((bool) $item->pit_overridden);
        $this->assertEquals(333_000, (float) $item->pit, 'PIT override phải giữ đúng số kế toán nhập, không bị công thức mới ghi đè');

        // Đồng bộ lại (không đổi employee) — override vẫn phải còn nguyên
        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();
        $item->refresh();
        $this->assertTrue((bool) $item->pit_overridden);
        $this->assertEquals(333_000, (float) $item->pit);
    }
}
