<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tính năng "Miễn chấm công": NV được đánh dấu is_attendance_exempt sẽ được hệ thống
 * tự điền đủ công khi lập bảng chấm công mới, không cần HR chấm tay.
 *
 * TC1: Lưu hồ sơ NV với is_attendance_exempt = true (store + update)
 * TC2: Tạo bảng chấm công mới -> NV miễn chấm công có days điền đủ 'X' cả tháng, cong = số ngày
 * TC3: NV không miễn chấm công -> record vẫn rỗng như trước (không đổi hành vi cũ)
 * TC4: End-to-end — bảng chấm công (đã auto-fill) được khóa -> payroll tính đủ standard_days
 *      cho NV miễn chấm công, không cần sửa PayrollService
 */
class EmployeeAttendanceExemptTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]); // isSuperAdmin() => true, bypass mọi permission
        $this->actingAs($this->admin);
    }

    private function employee(array $attr = []): Employee
    {
        return Employee::create(array_merge([
            'code'              => 'NV-' . fake()->unique()->numerify('####'),
            'name'              => fake()->name(),
            'status'            => 'active',
            'hire_date'         => '2025-01-01',
            'base_salary'       => 15_000_000,
            'insurance_subject' => true,
            'standard_days'     => 26,
            'created_by'        => $this->admin->id,
        ], $attr));
    }

    public function test_tc1_store_and_update_persist_attendance_exempt_flag(): void
    {
        $response = $this->post(route('admin.employees.store'), [
            'code' => 'NV-EXM1', 'name' => 'Giám đốc miễn công',
            'status' => 'active', 'employment_type' => 'full_time',
            'is_attendance_exempt' => true,
        ]);

        $response->assertRedirect();
        $employee = Employee::where('code', 'NV-EXM1')->firstOrFail();
        $this->assertTrue($employee->is_attendance_exempt);

        $response = $this->put(route('admin.employees.update', $employee), [
            'name' => $employee->name, 'status' => 'active', 'employment_type' => 'full_time',
            'is_attendance_exempt' => false,
        ]);
        $response->assertRedirect();
        $this->assertFalse($employee->fresh()->is_attendance_exempt);
    }

    public function test_tc2_new_attendance_sheet_auto_fills_full_days_for_exempt_employee(): void
    {
        $exempt = $this->employee(['is_attendance_exempt' => true]);

        $response = $this->post(route('admin.attendance.store'), ['period' => '2026-11']);
        $response->assertRedirect();

        $sheet  = AttendanceSheet::where('period', '2026-11')->firstOrFail();
        $record = AttendanceRecord::where('attendance_sheet_id', $sheet->id)
            ->where('employee_id', $exempt->id)->firstOrFail();

        $daysInMonth = 30; // tháng 11
        $this->assertCount($daysInMonth, $record->days);
        $this->assertSame('X', $record->days['1']);
        $this->assertSame('X', $record->days['30']);
        $this->assertSame($daysInMonth, $record->cong);
        $this->assertSame($daysInMonth, $record->tong);
    }

    public function test_tc3_non_exempt_employee_record_stays_empty_as_before(): void
    {
        $normal = $this->employee(['is_attendance_exempt' => false]);

        $this->post(route('admin.attendance.store'), ['period' => '2026-11']);

        $sheet  = AttendanceSheet::where('period', '2026-11')->firstOrFail();
        $record = AttendanceRecord::where('attendance_sheet_id', $sheet->id)
            ->where('employee_id', $normal->id)->firstOrFail();

        $this->assertEmpty($record->days);
        $this->assertSame(0, $record->cong);
    }

    public function test_tc5_mid_month_hire_only_fills_days_from_hire_date(): void
    {
        // Vào làm 20/11 -> chỉ được điền công từ ngày 20 đến 30 (11 ngày), không phải cả tháng
        $midMonthHire = $this->employee(['is_attendance_exempt' => true, 'hire_date' => '2026-11-20']);

        $this->post(route('admin.attendance.store'), ['period' => '2026-11']);

        $sheet  = AttendanceSheet::where('period', '2026-11')->firstOrFail();
        $record = AttendanceRecord::where('attendance_sheet_id', $sheet->id)
            ->where('employee_id', $midMonthHire->id)->firstOrFail();

        $this->assertCount(11, $record->days);
        $this->assertArrayNotHasKey('19', $record->days);
        $this->assertSame('X', $record->days['20']);
        $this->assertSame('X', $record->days['30']);
        $this->assertSame(11, $record->cong);
        $this->assertSame(11, $record->tong);
    }

    public function test_tc4_locked_sheet_with_autofilled_attendance_pays_full_standard_days(): void
    {
        $exempt = $this->employee(['is_attendance_exempt' => true, 'base_salary' => 20_000_000, 'standard_days' => 26]);

        $this->post(route('admin.attendance.store'), ['period' => '2026-11']);
        $sheet = AttendanceSheet::where('period', '2026-11')->firstOrFail();
        $sheet->update(['status' => 'locked']);

        $payroll = app(PayrollService::class)->createPayroll('2026-11');
        $item    = $payroll->items()->where('employee_id', $exempt->id)->firstOrFail();

        // days trong tháng 11 (30) > standard_days (26) -> tỷ lệ bị chặn ở 1.0 -> lương đủ 100%
        $this->assertSame(30, $item->working_days);
        $this->assertSame(26, $item->standard_days);
        $this->assertSame(20_000_000.0, (float) $item->base_salary);
    }
}
