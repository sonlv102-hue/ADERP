<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeTerminationService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR-TERMINATION-T01 — Acceptance cases C1..C11
 */
class EmployeeTerminationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]); // isSuperAdmin() => true
        $this->actingAs($this->admin);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

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

    private function lockedSheet(string $period, ?Employee $emp = null): AttendanceSheet
    {
        $sheet = AttendanceSheet::create([
            'code'       => 'CC-' . str_replace('-', '', $period),
            'period'     => $period,
            'status'     => 'locked',
            'created_by' => $this->admin->id,
        ]);
        if ($emp) {
            AttendanceRecord::create([
                'attendance_sheet_id' => $sheet->id,
                'employee_id'         => $emp->id,
                'days'                => '{}',
                'cong'                => 20, 'nghi_huong_luong' => 0,
                'nghi_khong_luong'    => 6, 'ot' => 0, 'tong' => 20,
            ]);
        }
        return $sheet;
    }

    private function terminate(Employee $e, string $date, array $extra = []): ?string
    {
        return app(EmployeeTerminationService::class)->terminate($e, array_merge(['termination_date' => $date], $extra));
    }

    // ── tests ───────────────────────────────────────────────────────────────

    /** C1 — nghỉ cuối tháng: có lương 09, không có 10 */
    public function test_c1_terminate_end_of_month(): void
    {
        $e = $this->employee();
        $this->lockedSheet('2026-09', $e);
        $this->lockedSheet('2026-10');

        $this->terminate($e, '2026-09-30');

        $sep = app(PayrollService::class)->createPayroll('2026-09');
        $oct = app(PayrollService::class)->createPayroll('2026-10');

        $this->assertTrue($sep->items()->where('employee_id', $e->id)->exists());
        $this->assertFalse($oct->items()->where('employee_id', $e->id)->exists());
    }

    /** C2 — nghỉ giữa tháng: vẫn trong lương 09, net không bị ép 0; không có kỳ 10 */
    public function test_c2_terminate_mid_month(): void
    {
        $e = $this->employee();
        $this->lockedSheet('2026-09', $e);
        $this->lockedSheet('2026-10');

        $this->terminate($e, '2026-09-15');

        $sep = app(PayrollService::class)->createPayroll('2026-09');
        $item = $sep->items()->where('employee_id', $e->id)->first();

        $this->assertNotNull($item);
        $this->assertGreaterThan(0, (float) $item->gross_salary);
        $this->assertFalse(app(PayrollService::class)->createPayroll('2026-10')->items()->where('employee_id', $e->id)->exists());
    }

    /** C3 — tra cứu lịch sử: bảng lương kỳ trước ngày nghỉ vẫn còn NV */
    public function test_c3_history_preserved(): void
    {
        $e = $this->employee();
        $this->lockedSheet('2026-08', $e);
        $aug = app(PayrollService::class)->createPayroll('2026-08');

        $this->terminate($e, '2026-09-15');

        $item = $aug->fresh()->items()->where('employee_id', $e->id)->first();
        $this->assertNotNull($item);
        $this->assertSame($e->id, $item->employee_id);
        $this->assertSame($e->code, $item->employee->code);
    }

    /** C4 — dropdown chọn NV theo ngày chứng từ */
    public function test_c4_search_dropdown_respects_document_date(): void
    {
        $e = $this->employee(['name' => 'Nguyen Van Nghi']);
        $this->terminate($e, '2026-09-15');

        // ngày chứng từ SAU ngày thôi việc → không còn trong dropdown
        $after = array_column($this->getJson(route('search.employees', ['date' => '2026-10-01']))->json('data'), 'value');
        $this->assertNotContains($e->id, $after);

        // ngày chứng từ TRƯỚC/ĐÚNG ngày thôi việc → vẫn còn
        $before = array_column($this->getJson(route('search.employees', ['date' => '2026-09-10']))->json('data'), 'value');
        $this->assertContains($e->id, $before);
    }

    /** C5 — hủy thôi việc: về active, field null, có audit log */
    public function test_c5_cancel_termination(): void
    {
        $e = $this->employee();
        $this->terminate($e, '2026-09-15', ['termination_reason' => 'X']);
        app(EmployeeTerminationService::class)->cancelTermination($e->fresh(), 'Nhập nhầm ngày');

        $e->refresh();
        $this->assertSame('active', $e->status->value);
        $this->assertNull($e->termination_date);
        $this->assertNull($e->terminated_by);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $e->id, 'description' => 'cancel_termination']);
    }

    /** C6 — phân quyền: user không có quyền → 403 (giao diện + API) */
    public function test_c6_permission_required(): void
    {
        $plain = User::factory()->create(['is_active' => true]);
        $e = $this->employee();

        $this->actingAs($plain)
            ->post(route('admin.employees.terminate', $e), ['termination_date' => '2026-09-15'])
            ->assertForbidden();

        $this->actingAs($plain)
            ->post(route('admin.employees.cancel-termination', $e), ['reason' => 'x'])
            ->assertForbidden();
    }

    /** C7 — kỳ đã khóa: bảng lương tháng trước không đổi khi NV nghỉ tháng sau */
    public function test_c7_locked_payroll_untouched(): void
    {
        $e = $this->employee();
        $this->lockedSheet('2026-08', $e);
        $aug = app(PayrollService::class)->createPayroll('2026-08');
        $aug->update(['is_locked' => true, 'status' => 'confirmed']);

        $before = $aug->fresh()->only(['total_gross', 'total_net_salary', 'total_deductions']);
        $beforeItems = $aug->items()->orderBy('id')->get(['employee_id', 'gross_salary', 'net_salary'])->toArray();

        $this->terminate($e, '2026-09-15');

        $this->assertSame($before, $aug->fresh()->only(['total_gross', 'total_net_salary', 'total_deductions']));
        $this->assertSame($beforeItems, $aug->fresh()->items()->orderBy('id')->get(['employee_id', 'gross_salary', 'net_salary'])->toArray());
    }

    /** C8 — terminate NV đã nghỉ → lỗi */
    public function test_c8_double_terminate_rejected(): void
    {
        $e = $this->employee();
        $this->terminate($e, '2026-09-15');

        $this->post(route('admin.employees.terminate', $e->fresh()), ['termination_date' => '2026-10-01'])
            ->assertSessionHas('error');
    }

    /** C9 — form update không được đổi status sang resigned */
    public function test_c9_form_cannot_set_resigned(): void
    {
        $e = $this->employee();

        $this->put(route('admin.employees.update', $e), [
            'name' => $e->name, 'status' => 'resigned', 'employment_type' => 'full_time',
        ])->assertSessionHas('error');

        $this->assertSame('active', $e->fresh()->status->value);
    }

    /** C10 — báo cáo biến động */
    public function test_c10_headcount_report(): void
    {
        $stay = $this->employee(['hire_date' => '2024-01-01']);
        $joined = $this->employee(['hire_date' => '2026-09-05']);
        $left = $this->employee(['hire_date' => '2024-01-01']);
        $this->terminate($left, '2026-09-20');

        $res = $this->get(route('admin.employees.headcount', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $res->assertOk();
        $summary = $res->viewData('page')['props']['summary'];

        $this->assertSame(2, $summary['opening']);   // stay + left
        $this->assertSame(1, $summary['increase']);  // joined
        $this->assertSame(1, $summary['decrease']);  // left
        $this->assertSame(2, $summary['closing']);   // stay + joined
    }

    /** C11 — nghỉ trong kỳ đã khóa → trả cảnh báo */
    public function test_c11_warning_when_termination_in_locked_period(): void
    {
        $e = $this->employee();
        $this->lockedSheet('2026-09', $e); // attendance sheet locked cho kỳ 09

        $warning = $this->terminate($e, '2026-09-15');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('kỳ dữ liệu đã khóa', $warning);
    }

    /** C12 — tìm kiếm NV không phân biệt hoa/thường + lọc theo ngày chứng từ cùng lúc */
    public function test_c12_search_case_insensitive_and_effective_date(): void
    {
        $e = $this->employee(['name' => 'Nguyễn Văn A']);
        $this->terminate($e, '2026-09-15'); // 15/09 là ngày làm việc cuối cùng

        // ngày <= ngày thôi việc + query CHỮ HOA có dấu → vẫn tìm được
        $onLastDay = array_column(
            $this->getJson(route('search.employees', ['date' => '2026-09-15', 'q' => 'NGUYỄN']))->json('data'),
            'value'
        );
        $this->assertContains($e->id, $onLastDay);

        // ngày > ngày thôi việc → không trả cho nghiệp vụ mới (dù query đúng tên)
        $dayAfter = array_column(
            $this->getJson(route('search.employees', ['date' => '2026-09-16', 'q' => 'nguyễn']))->json('data'),
            'value'
        );
        $this->assertNotContains($e->id, $dayAfter);
    }
}
