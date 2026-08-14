<?php

namespace Tests\Feature\Payroll;

use App\Enums\PayrollStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Payroll Draft — auto-populate & manual-override protection (cải tiến sau rà soát).
 *
 * Case 1: createPayroll() tự tính đủ BHXH/BHYT/BHTN cho nhân viên.
 * Case 2: syncFromEmployees() Bước B — tự bổ sung nhân viên mới/active còn thiếu.
 * Case 3: syncFromEmployees() Bước A — cập nhật lại khi Employee đổi base_salary (chưa override).
 * Case 4: Manual override (BHXH + PIT) không bị mất khi Employee master thay đổi.
 * Case 5: Cảnh báo "thiếu mức lương đóng BHXH" khi insurance_subject=true & base_salary<=0.
 * Case 6: Payroll Confirmed/Locked — sync không thêm mới, không cập nhật item.
 * Case 7: Sync nhiều lần không tạo trùng PayrollItem cho cùng employee_id.
 */
class PayrollDraftSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employeeA;
    private AttendanceSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);

        Permission::firstOrCreate(['name' => 'accounting.view']);
        Permission::firstOrCreate(['name' => 'hr.employees.update']);
        $this->user->givePermissionTo(['accounting.view', 'hr.employees.update']);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->user->assignRole($adminRole);

        $this->actingAs($this->user);

        $this->employeeA = Employee::create([
            'code'              => 'NV-A',
            'name'              => 'Nhân viên A',
            'status'            => 'active',
            'base_salary'       => 15_000_000,
            'insurance_subject' => true,
            'standard_days'     => 26,
            'created_by'        => $this->user->id,
        ]);

        $this->sheet = AttendanceSheet::create([
            'code'       => 'CC-202605',
            'period'     => '2026-05',
            'status'     => 'locked',
            'created_by' => $this->user->id,
        ]);
        AttendanceRecord::create([
            'attendance_sheet_id' => $this->sheet->id,
            'employee_id'         => $this->employeeA->id,
            'days'                => '{}',
            'cong'                => 26,
            'nghi_huong_luong'    => 0,
            'nghi_khong_luong'    => 0,
            'ot'                  => 0,
            'tong'                => 26,
        ]);
    }

    /** Case 1 */
    public function test_create_payroll_auto_calculates_full_insurance_for_employee(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05'])->assertRedirect();

        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items()->where('employee_id', $this->employeeA->id)->first();

        $this->assertNotNull($item);
        // insurance_base = min(15_000_000, cap) = 15_000_000
        $this->assertEquals(round(15_000_000 * 0.08),  (float) $item->bhxh_employee);
        $this->assertEquals(round(15_000_000 * 0.015), (float) $item->bhyt_employee);
        $this->assertEquals(round(15_000_000 * 0.01),  (float) $item->bhtn_employee);
        $this->assertEquals(round(15_000_000 * 0.175), (float) $item->bhxh_employer);
        $this->assertEquals(round(15_000_000 * 0.03),  (float) $item->bhyt_employer);
        $this->assertEquals(round(15_000_000 * 0.01),  (float) $item->bhtn_employer);
        $this->assertFalse((bool) $item->insurance_overridden);
        $this->assertFalse((bool) $item->pit_overridden);
    }

    /** Case 2 */
    public function test_sync_adds_new_active_employee_missing_from_draft_payroll(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $this->assertCount(1, $payroll->items);

        $employeeB = Employee::create([
            'code' => 'NV-B', 'name' => 'Nhân viên B mới', 'status' => 'active',
            'base_salary' => 12_000_000, 'insurance_subject' => true, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $response = $this->post(route('accounting.payrolls.sync-employees', $payroll->id));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payroll->refresh();
        $this->assertCount(2, $payroll->items);

        $itemB = $payroll->items()->where('employee_id', $employeeB->id)->first();
        $this->assertNotNull($itemB, 'Employee B mới tuyển phải được tự động thêm vào Payroll Draft');
        $this->assertEquals(12_000_000, (float) $itemB->base_salary);
        $this->assertEquals(round(12_000_000 * 0.08), (float) $itemB->bhxh_employee);
    }

    /** Case 2b — nhân viên probation cũng được tự thêm */
    public function test_sync_adds_probation_employee_missing_from_draft_payroll(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();

        Employee::create([
            'code' => 'NV-C', 'name' => 'NV thử việc', 'status' => 'probation',
            'base_salary' => 8_000_000, 'insurance_subject' => false, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $this->post(route('accounting.payrolls.sync-employees', $payroll->id));

        $payroll->refresh();
        $this->assertCount(2, $payroll->items);
    }

    /** Case 3 */
    public function test_sync_recalculates_when_employee_base_salary_changes_without_override(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items()->first();
        $oldBhxh = (float) $item->bhxh_employee;

        $this->employeeA->update(['base_salary' => 20_000_000]);

        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();

        $item->refresh();
        $this->assertEquals(20_000_000, (float) $item->base_salary);
        $this->assertNotEquals($oldBhxh, (float) $item->bhxh_employee);
        $this->assertEquals(round(20_000_000 * 0.08), (float) $item->bhxh_employee);
    }

    /** Case 4 */
    public function test_manual_override_survives_employee_master_update(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items()->first();

        // Kế toán override BHXH + PIT (admin)
        $this->put(route('accounting.payrolls.items.update', [$payroll->id, $item->id]), [
            'base_salary'                 => $item->base_salary,
            'insurance_subject'           => true,
            'insurance_override_enabled'  => true,
            'bhxh_employer'               => 999_000,
            'bhyt_employer'               => 111_000,
            'bhtn_employer'               => 11_000,
            'bhxh_employee'               => 888_000,
            'bhyt_employee'               => 22_000,
            'bhtn_employee'               => 12_000,
            'pit_override_enabled'        => true,
            'pit_override'                => 555_000,
            'override_reason'             => 'Truy thu BHXH tháng trước',
        ])->assertRedirect();

        $item->refresh();
        $this->assertTrue((bool) $item->insurance_overridden);
        $this->assertTrue((bool) $item->pit_overridden);
        $this->assertEquals(888_000, (float) $item->bhxh_employee);
        $this->assertEquals(555_000, (float) $item->pit);
        $this->assertEquals('Truy thu BHXH tháng trước', $item->override_reason);
        $this->assertNotNull($item->overridden_by);
        $this->assertNotNull($item->overridden_at);

        // HR sửa hồ sơ NV (đổi base_salary) → PayrollService::syncEmployeeToDraftPayrolls() tự chạy
        $this->put(route('admin.employees.update', $this->employeeA->id), [
            'name'             => $this->employeeA->name,
            'status'           => 'active',
            'employment_type'  => 'full_time',
            'base_salary'      => 25_000_000,
            'insurance_subject' => true,
        ])->assertRedirect();

        $item->refresh();
        $this->assertEquals(25_000_000, (float) $item->base_salary, 'base_salary vẫn đồng bộ bình thường (không bị override)');
        $this->assertEquals(888_000, (float) $item->bhxh_employee, 'BHXH override phải được giữ nguyên, không bị tính lại');
        $this->assertEquals(555_000, (float) $item->pit, 'PIT override phải được giữ nguyên, không bị tính lại');
        $this->assertTrue((bool) $item->insurance_overridden);
        $this->assertTrue((bool) $item->pit_overridden);
    }

    /** Case 4b — bỏ tick override trên updateItem() → quay lại tính theo công thức (reset) */
    public function test_unchecking_override_resets_to_formula_value(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items()->first();

        $this->put(route('accounting.payrolls.items.update', [$payroll->id, $item->id]), [
            'base_salary' => $item->base_salary,
            'insurance_subject' => true,
            'insurance_override_enabled' => true,
            'bhxh_employer' => 999_000, 'bhyt_employer' => 1, 'bhtn_employer' => 1,
            'bhxh_employee' => 777_000, 'bhyt_employee' => 1, 'bhtn_employee' => 1,
        ])->assertRedirect();

        $item->refresh();
        $this->assertTrue((bool) $item->insurance_overridden);

        // Sửa lại, lần này bỏ tick override
        $this->put(route('accounting.payrolls.items.update', [$payroll->id, $item->id]), [
            'base_salary' => $item->base_salary,
            'insurance_subject' => true,
            'insurance_override_enabled' => false,
        ])->assertRedirect();

        $item->refresh();
        $this->assertFalse((bool) $item->insurance_overridden);
        $this->assertEquals(round((float) $item->base_salary * 0.08), (float) $item->bhxh_employee);
    }

    /** Regression: override BHXH phải về 0 (không giữ số cũ) khi NV không còn thuộc diện đóng BHXH */
    public function test_insurance_override_is_cleared_when_employee_no_longer_insurance_subject(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items()->first();

        $this->put(route('accounting.payrolls.items.update', [$payroll->id, $item->id]), [
            'base_salary' => $item->base_salary,
            'insurance_subject' => true,
            'insurance_override_enabled' => true,
            'bhxh_employer' => 999_000, 'bhyt_employer' => 1, 'bhtn_employer' => 1,
            'bhxh_employee' => 777_000, 'bhyt_employee' => 1, 'bhtn_employee' => 1,
        ])->assertRedirect();

        $item->refresh();
        $this->assertTrue((bool) $item->insurance_overridden);
        $this->assertEquals(777_000, (float) $item->bhxh_employee);

        // HR chuyển NV sang diện KHÔNG đóng BHXH (VD: hợp đồng thời vụ)
        $this->employeeA->update(['insurance_subject' => false]);
        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();

        $item->refresh();
        $this->assertEquals(0, (float) $item->bhxh_employee, 'BHXH phải về 0 khi NV không còn thuộc diện đóng BHXH, không giữ số override cũ');
        $this->assertEquals(0, (float) $item->bhyt_employee);
        $this->assertEquals(0, (float) $item->bhtn_employee);
        $this->assertFalse((bool) $item->insurance_overridden, 'Cờ override phải tự tắt vì không còn ý nghĩa');
    }

    /** Case 5 */
    public function test_missing_base_salary_warning_flag_for_insurance_subject_employee(): void
    {
        // Đặt tên bắt đầu bằng "Z" để đảm bảo sort sau "Nhân viên A" (createPayroll() orderBy('name'))
        Employee::create([
            'code' => 'NV-D', 'name' => 'Z - NV chưa có lương', 'status' => 'active',
            'base_salary' => 0, 'insurance_subject' => true, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();

        $response = $this->get(route('accounting.payrolls.show', $payroll->id));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('items', 2)
            ->where('items.1.missing_insurance_base_salary', true)
            ->where('items.0.missing_insurance_base_salary', false)
        );
    }

    /** Case 6 */
    public function test_sync_blocked_when_payroll_confirmed_or_locked(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $this->post(route('accounting.payrolls.confirm', $payroll->id))->assertRedirect();

        $payroll->refresh();
        $this->assertEquals(PayrollStatus::Confirmed->value, $payroll->status->value);

        Employee::create([
            'code' => 'NV-LATE', 'name' => 'NV tuyển sau khi confirm', 'status' => 'active',
            'base_salary' => 10_000_000, 'insurance_subject' => true, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $response = $this->post(route('accounting.payrolls.sync-employees', $payroll->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $payroll->refresh();
        $this->assertCount(1, $payroll->items, 'Payroll đã Confirmed không được tự thêm nhân viên mới');
    }

    /** Case 6b — is_locked cũng phải chặn, kể cả khi còn Draft */
    public function test_sync_blocked_when_payroll_draft_but_locked(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $payroll->update(['is_locked' => true, 'locked_by' => $this->user->id, 'locked_at' => now()]);

        $this->employeeA->update(['base_salary' => 30_000_000]);

        $response = $this->post(route('accounting.payrolls.sync-employees', $payroll->id));
        $response->assertSessionHas('error');

        $item = $payroll->items()->first();
        $this->assertNotEquals(30_000_000, (float) $item->base_salary, 'Payroll đã khóa không được tự cập nhật từ hồ sơ NV');
    }

    /** Case 7 */
    public function test_sync_multiple_times_does_not_create_duplicate_payroll_items(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();

        Employee::create([
            'code' => 'NV-E', 'name' => 'NV bổ sung', 'status' => 'active',
            'base_salary' => 9_000_000, 'insurance_subject' => true, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();
        $payroll->refresh();
        $this->assertCount(2, $payroll->items);

        // Gọi sync thêm 2 lần nữa — không có nhân viên mới nào khác
        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();
        $this->post(route('accounting.payrolls.sync-employees', $payroll->id))->assertRedirect();

        $payroll->refresh();
        $this->assertCount(2, $payroll->items, 'Sync nhiều lần không được tạo trùng PayrollItem');

        $employeeIds = $payroll->items()->pluck('employee_id')->all();
        $this->assertEquals(count($employeeIds), count(array_unique($employeeIds)), 'Không có employee_id trùng lặp trong cùng 1 Payroll');
    }

    /** Regression: bảng lương có dòng legacy (employee_id null) — Bước B phải bỏ qua, không tạo trùng */
    public function test_sync_skips_step_b_when_payroll_has_legacy_null_employee_id_item(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();

        // Giả lập dòng lương cũ (trước migration employee_id) — chỉ có user_id
        \App\Models\PayrollItem::create([
            'payroll_id' => $payroll->id,
            'employee_id' => null,
            'user_id' => $this->user->id,
            'base_salary' => 5_000_000,
            'net_salary' => 5_000_000,
            'status' => 'pending',
        ]);

        $employeeNew = Employee::create([
            'code' => 'NV-LEGACY-TEST', 'name' => 'NV mới khi có dòng legacy', 'status' => 'active',
            'base_salary' => 9_000_000, 'insurance_subject' => true, 'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $response = $this->post(route('accounting.payrolls.sync-employees', $payroll->id));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $payroll->refresh();
        $this->assertNull(
            $payroll->items()->where('employee_id', $employeeNew->id)->first(),
            'Không được tự thêm nhân viên mới khi bảng lương còn dòng legacy employee_id null (tránh trùng dòng lương)'
        );
    }

    /** Case 7b — chống trùng ở tầng DB (unique constraint) */
    public function test_database_unique_constraint_blocks_duplicate_payroll_item_for_same_employee(): void
    {
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        app(PayrollService::class)->buildItem($payroll, $this->employeeA);
    }
}
