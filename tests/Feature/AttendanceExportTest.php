<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: exportExcel trả về file xlsx đúng content-type
 * TC2: Không phải admin -> 403
 */
class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->syncRoles([$adminRole]);
        $this->actingAs($this->admin);
    }

    private function makeSheet(): AttendanceSheet
    {
        $sheet = AttendanceSheet::create([
            'code'       => AttendanceSheet::generateCode('2026-07'),
            'period'     => '2026-07',
            'status'     => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $employee = Employee::create([
            'code'       => 'NV-0001',
            'name'       => 'Nguyễn Văn A',
            'department' => 'Kế toán',
            'created_by' => $this->admin->id,
        ]);

        $record = AttendanceRecord::create([
            'attendance_sheet_id' => $sheet->id,
            'employee_id'         => $employee->id,
            'days'                => ['1' => 'X', '2' => 'X', '3' => 'P'],
        ]);
        $record->recalculate();

        return $sheet;
    }

    public function test_export_excel_returns_xlsx(): void
    {
        $sheet = $this->makeSheet();

        $response = $this->get(route('admin.attendance.export-excel', $sheet->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_forbidden_for_non_admin(): void
    {
        $sheet = $this->makeSheet();

        $this->admin->syncRoles([]);

        $response = $this->get(route('admin.attendance.export-excel', $sheet->id));

        $response->assertForbidden();
    }
}
