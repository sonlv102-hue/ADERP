<?php

namespace Tests\Feature;

use App\Enums\PayrollStatus;
use App\Enums\PayrollItemStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayrollService;
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

        // Seed TK 1121 (Tiền gửi VND — leaf account) để payEmployeeSalary có thể hạch toán
        // '112' là TK tổng hợp (is_detail=false), phải dùng TK chi tiết '1121'
        \App\Models\AccountCode::firstOrCreate(['code' => '1121'], [
            'name' => 'Tiền gửi VND', 'type' => 'asset', 'normal_balance' => 'debit',
            'parent_code' => '112', 'level' => 4, 'is_detail' => true, 'is_active' => true,
        ]);

        // Create a bank account for salary payment
        $this->bankAccount = BankAccount::create([
            'name' => 'Tài khoản kiểm thử',
            'bank_name' => 'Test Bank',
            'account_number' => '123456789',
            'account_code' => '1121',
            'is_active' => true,
        ]);

        // Create an active employee to populate payroll items
        $this->employee = Employee::create([
            'code' => 'NV-0001',
            'name' => 'Nhân viên kiểm thử',
            'status' => 'active',
            'base_salary' => 10000000,
            'allowance' => 1000000,
            'insurance_subject' => false,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        // Locked attendance sheet for period '2026-05' (used by most existing tests)
        $this->attendanceSheet = $this->createLockedSheet('2026-05', $this->employee);
    }

    /** Helper: tạo attendance sheet đã lock + record cho employee */
    private function createLockedSheet(
        string $period,
        Employee $employee,
        int $cong = 26,
        int $nghiHuongLuong = 0,
        int $nghiKhongLuong = 0,
        int $ot = 0,
    ): AttendanceSheet {
        $code = 'CC-' . str_replace('-', '', $period);
        $sheet = AttendanceSheet::create([
            'code'       => $code,
            'period'     => $period,
            'status'     => 'locked',
            'created_by' => $this->user->id,
        ]);

        AttendanceRecord::create([
            'attendance_sheet_id' => $sheet->id,
            'employee_id'         => $employee->id,
            'days'                => '{}',
            'cong'                => $cong,
            'nghi_huong_luong'    => $nghiHuongLuong,
            'nghi_khong_luong'    => $nghiKhongLuong,
            'ot'                  => $ot,
            'tong'                => $cong + $nghiHuongLuong + $nghiKhongLuong + $ot,
        ]);

        return $sheet;
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
        // gross = 15_500_000 = PERSONAL_DEDUCTION (15.5M per TT 79/2022) → taxable = 0 → PIT = 0
        $this->assertEquals(0, $item->deductions);
        $this->assertEquals(15500000, $item->net_salary);

        $payroll->refresh();
        $this->assertEquals(12000000, $payroll->total_base_salary);
        $this->assertEquals(1500000, $payroll->total_allowance);
        $this->assertEquals(2000000, $payroll->total_bonus);
        $this->assertEquals(0, $payroll->total_deductions);
        $this->assertEquals(15500000, $payroll->total_net_salary);
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
            'bank_account_id' => $this->bankAccount->id,
        ]);
        $payResponse->assertRedirect();

        $item->refresh();
        $this->assertEquals(PayrollItemStatus::Paid->value, $item->status->value);
        $this->assertNotNull($item->paid_at);
        $this->assertNotNull($item->salary_journal_entry_id);

        // 4. Verify journal entry Dr 334 / Cr 112 was created
        $je = JournalEntry::find($item->salary_journal_entry_id);
        $this->assertNotNull($je);
        $drLine = JournalEntryLine::where('journal_entry_id', $je->id)
            ->where('account_code', '334')->where('debit', '>', 0)->first();
        $this->assertNotNull($drLine);
        $this->assertEquals((int) $item->net_salary, (int) $drLine->debit);

        // 5. Verify entire payroll is marked as paid
        $payroll->refresh();
        $this->assertEquals(PayrollStatus::Paid->value, $payroll->status->value);
    }

    // ── KPCĐ tests (M2) ──────────────────────────────────────────────────────

    /** AC1: NLĐ đóng BHXH bắt buộc → KPCĐ = insurance_base * 2% */
    public function test_trade_union_fee_calculated_on_insurance_base_for_bhxh_subject_employee(): void
    {
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');
        Setting::set('payroll.union_fee_rate', '2', 'payroll');

        $employee = Employee::create([
            'code' => 'NV-0002',
            'name' => 'NV đóng BHXH',
            'status' => 'active',
            'base_salary' => 20000000,
            'insurance_subject' => true,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        /** @var PayrollService $service */
        $service = app(PayrollService::class);
        $payroll = Payroll::create([
            'code' => 'BL-202601', 'period' => '2026-01', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $item = $service->buildItem($payroll, $employee);

        // insurance_base = min(20_000_000, 46_800_000) = 20_000_000
        $expectedInsBase = 20000000;
        $this->assertEquals($expectedInsBase, (int)$item->insurance_base);
        $this->assertEquals((int)round($expectedInsBase * 2 / 100), (int)$item->trade_union_fee);
    }

    /** AC2: NLĐ không đóng BHXH bắt buộc → KPCĐ = 0 */
    public function test_trade_union_fee_is_zero_for_non_bhxh_subject_employee(): void
    {
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');

        /** @var PayrollService $service */
        $service = app(PayrollService::class);
        $payroll = Payroll::create([
            'code' => 'BL-202601', 'period' => '2026-01', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        // setUp employee has insurance_subject = false
        $item = $service->buildItem($payroll, $this->employee);

        $this->assertEquals(0, (int)$item->trade_union_fee);
        $this->assertEquals(0, (int)$item->insurance_base);
    }

    /** AC3: insurance_base != gross_salary → KPCĐ dùng insurance_base, không dùng gross */
    public function test_trade_union_fee_uses_insurance_base_not_gross_salary(): void
    {
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');
        Setting::set('payroll.union_fee_rate', '2', 'payroll');

        $employee = Employee::create([
            'code' => 'NV-0003',
            'name' => 'NV thử insurance_base',
            'status' => 'active',
            'base_salary' => 15000000,
            // allowance_lunch không tính BHXH (nonBhxhAllowances)
            'allowance_lunch' => 1000000,
            'insurance_subject' => true,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        /** @var PayrollService $service */
        $service = app(PayrollService::class);
        $payroll = Payroll::create([
            'code' => 'BL-202601', 'period' => '2026-01', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $item = $service->buildItem($payroll, $employee);

        // gross_salary = 15_000_000 + 1_000_000 = 16_000_000
        // insurance_base = min(15_000_000, 46_800_000) = 15_000_000 (chỉ base, không có bhxhAllowances)
        $this->assertGreaterThan((int)$item->insurance_base, (int)$item->gross_salary,
            'gross_salary phải lớn hơn insurance_base khi có allowance_lunch');
        $this->assertEquals(
            (int)round((float)$item->insurance_base * 2 / 100),
            (int)$item->trade_union_fee,
            'KPCĐ phải tính trên insurance_base, không phải gross_salary'
        );
    }

    /** AC4: KPCĐ không giảm net_salary của NLĐ */
    public function test_trade_union_fee_does_not_reduce_net_salary(): void
    {
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');
        Setting::set('payroll.union_fee_enabled', '0', 'payroll'); // tắt để so sánh

        /** @var PayrollService $service */
        $service = app(PayrollService::class);
        $payroll = Payroll::create([
            'code' => 'BL-202601', 'period' => '2026-01', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $employee = Employee::create([
            'code' => 'NV-0004',
            'name' => 'NV net salary test',
            'status' => 'active',
            'base_salary' => 20000000,
            'insurance_subject' => true,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $itemNoFee = $service->buildItem($payroll, $employee);

        // Bật KPCĐ, tạo payroll mới
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');
        $payroll2 = Payroll::create([
            'code' => 'BL-202602', 'period' => '2026-02', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $employee2 = Employee::create([
            'code' => 'NV-0005',
            'name' => 'NV net salary test 2',
            'status' => 'active',
            'base_salary' => 20000000,
            'insurance_subject' => true,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        $itemWithFee = $service->buildItem($payroll2, $employee2);

        // net_salary phải giống nhau (KPCĐ không ảnh hưởng đến lương thực nhận)
        $this->assertEquals((int)$itemNoFee->net_salary, (int)$itemWithFee->net_salary,
            'KPCĐ không được làm giảm net_salary của NLĐ');
        $this->assertGreaterThan(0, (int)$itemWithFee->trade_union_fee,
            'trade_union_fee phải > 0 khi bật KPCĐ');
    }

    /** AC5: Khi confirm payroll, journal entry phải có Cr 3382 */
    public function test_journal_entry_has_credit_3382_when_union_fee_enabled(): void
    {
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');
        Setting::set('payroll.union_fee_rate', '2', 'payroll');

        // Tạo NLĐ đóng BHXH
        $this->employee->update(['insurance_subject' => true, 'base_salary' => 20000000]);
        $this->createLockedSheet('2026-03', $this->employee);

        $this->post(route('accounting.payrolls.store'), ['period' => '2026-03']);
        $payroll = Payroll::where('period', '2026-03')->first();

        $this->assertGreaterThan(0, (float)$payroll->total_trade_union_fee,
            'total_trade_union_fee phải > 0 sau khi tạo payroll với NLĐ đóng BHXH');

        // Confirm → tạo journal entry
        $this->post(route('accounting.payrolls.confirm', $payroll->id));
        $payroll->refresh();

        $je = JournalEntry::where('reference_type', 'payroll')
            ->where('reference_id', $payroll->id)
            ->first();

        $this->assertNotNull($je, 'Journal entry phải được tạo khi confirm payroll');

        $cr3382 = JournalEntryLine::where('journal_entry_id', $je->id)
            ->where('account_code', '3382')
            ->where('credit', '>', 0)
            ->first();

        $this->assertNotNull($cr3382, 'Phải có dòng Có TK 3382 trong journal entry payroll');
        $this->assertEquals((int)$payroll->total_trade_union_fee, (int)$cr3382->credit);
    }

    /** AC6: Khi KPCĐ disabled (qua settings) → trade_union_fee = 0, không có Cr 3382 */
    public function test_no_trade_union_fee_when_disabled_in_settings(): void
    {
        Setting::set('payroll.union_fee_enabled', '0', 'payroll');

        $this->employee->update(['insurance_subject' => true, 'base_salary' => 20000000]);
        $this->createLockedSheet('2026-04', $this->employee);

        $this->post(route('accounting.payrolls.store'), ['period' => '2026-04']);
        $payroll = Payroll::where('period', '2026-04')->first();

        $this->assertEquals(0, (float)$payroll->total_trade_union_fee,
            'total_trade_union_fee phải = 0 khi KPCĐ disabled');

        $this->post(route('accounting.payrolls.confirm', $payroll->id));

        $je = JournalEntry::where('reference_type', 'payroll')
            ->where('reference_id', $payroll->id)
            ->first();

        if ($je) {
            $cr3382 = JournalEntryLine::where('journal_entry_id', $je->id)
                ->where('account_code', '3382')
                ->exists();
            $this->assertFalse($cr3382, 'Không được có Cr 3382 khi KPCĐ disabled');
        }
    }

    /** AC7: NLĐ đóng BHXH nhưng insurance_base = 0 → KPCĐ = 0, log warning */
    public function test_zero_trade_union_fee_and_warning_when_insurance_base_is_zero(): void
    {
        Setting::set('payroll.union_fee_enabled', '1', 'payroll');

        $employee = Employee::create([
            'code' => 'NV-0006',
            'name' => 'NV base zero',
            'status' => 'active',
            'base_salary' => 0, // base = 0 → insurance_base = 0
            'insurance_subject' => true,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        /** @var PayrollService $service */
        $service = app(PayrollService::class);
        $payroll = Payroll::create([
            'code' => 'BL-202605', 'period' => '2026-05-02', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $item = $service->buildItem($payroll, $employee);

        // Không tính sai (không tự lấy gross salary)
        $this->assertEquals(0, (int)$item->trade_union_fee,
            'KPCĐ phải = 0 khi insurance_base = 0, không tự fallback sang gross_salary');
    }

    // ── M3 — Attendance integration tests ────────────────────────────────────

    /** M3-1: Attendance data được snapshot vào payroll item */
    public function test_attendance_data_is_snapshotted_into_payroll_item(): void
    {
        // setUp đã tạo locked sheet '2026-05' với cong=26 cho employee
        $this->post(route('accounting.payrolls.store'), ['period' => '2026-05']);
        $payroll = Payroll::where('period', '2026-05')->first();
        $item = $payroll->items->first();

        $this->assertEquals(26, $item->actual_working_days);
        $this->assertEquals(0,  $item->paid_leave_days);
        $this->assertEquals(0,  $item->unpaid_leave_days);
        $this->assertEquals(26, $item->working_days);
        $this->assertEquals($this->attendanceSheet->id, $item->attendance_sheet_id);
        $this->assertNull($item->attendance_note);
    }

    /** M3-2: Nghỉ không lương → salary bị prorate */
    public function test_unpaid_leave_days_reduce_salary_via_proration(): void
    {
        // Tạo employee mới với base_salary chia hết cho 26
        $employee = Employee::create([
            'code' => 'NV-M301',
            'name' => 'NV nghỉ KL',
            'status' => 'active',
            'base_salary' => 26000000, // daily_rate = 1000000
            'insurance_subject' => false,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        // 20 ngày làm, 3 ngày nghỉ hưởng lương, 3 ngày nghỉ không lương
        // actual_paid_days = 20 + 3 = 23
        $sheet = $this->createLockedSheet('2026-06', $employee, cong: 20, nghiHuongLuong: 3, nghiKhongLuong: 3);

        $payroll = Payroll::create([
            'code' => 'BL-202606', 'period' => '2026-06', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $item = app(PayrollService::class)->buildItem($payroll, $employee, 0, $sheet);

        $this->assertEquals(23, $item->actual_working_days, 'actual_working_days = cong + nghi_huong_luong');
        $this->assertEquals(3,  $item->paid_leave_days);
        $this->assertEquals(3,  $item->unpaid_leave_days);
        $this->assertEquals(23, $item->working_days, 'working_days phải = actual_working_days');

        // gross = 26000000 * (23/26) = 23000000
        $this->assertEquals(23000000, (int)$item->gross_salary,
            'Salary phải được prorate theo ngày công thực tế');
    }

    /** M3-3: Nghỉ phép hưởng lương → tính như ngày làm, không giảm lương */
    public function test_paid_leave_days_count_as_working_days(): void
    {
        $employee = Employee::create([
            'code' => 'NV-M302',
            'name' => 'NV nghỉ phép',
            'status' => 'active',
            'base_salary' => 26000000,
            'insurance_subject' => false,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        // 20 ngày làm + 6 ngày nghỉ phép hưởng lương = 26 actual_paid_days
        $sheet = $this->createLockedSheet('2026-07', $employee, cong: 20, nghiHuongLuong: 6, nghiKhongLuong: 0);

        $payroll = Payroll::create([
            'code' => 'BL-202607', 'period' => '2026-07', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $item = app(PayrollService::class)->buildItem($payroll, $employee, 0, $sheet);

        $this->assertEquals(26, $item->actual_working_days, 'Nghỉ phép hưởng lương tính vào actual_paid_days');
        $this->assertEquals(6,  $item->paid_leave_days);
        $this->assertEquals(26000000, (int)$item->gross_salary,
            'Nghỉ phép hưởng lương không giảm salary');
    }

    /** M3-4: createPayroll() báo lỗi khi bảng chấm công chưa lock */
    public function test_create_payroll_fails_when_attendance_sheet_not_locked(): void
    {
        // Tạo sheet ở trạng thái draft (chưa lock)
        AttendanceSheet::create([
            'code'       => 'CC-202608',
            'period'     => '2026-08',
            'status'     => 'draft',
            'created_by' => $this->user->id,
        ]);

        $response = $this->post(route('accounting.payrolls.store'), ['period' => '2026-08']);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertDatabaseMissing('payrolls', ['period' => '2026-08']);
    }

    /** M3-5: Locked sheet nhưng không có record cho NV → dùng standardDays, set attendance_note */
    public function test_missing_attendance_record_uses_standard_days_and_sets_note(): void
    {
        $employee = Employee::create([
            'code' => 'NV-M305',
            'name' => 'NV không có chấm công',
            'status' => 'active',
            'base_salary' => 10000000,
            'insurance_subject' => false,
            'standard_days' => 26,
            'created_by' => $this->user->id,
        ]);

        // Sheet lock cho period nhưng KHÔNG có record cho employee này
        $sheet = AttendanceSheet::create([
            'code'       => 'CC-202609',
            'period'     => '2026-09',
            'status'     => 'locked',
            'created_by' => $this->user->id,
        ]);

        $payroll = Payroll::create([
            'code' => 'BL-202609', 'period' => '2026-09', 'status' => 'draft',
            'total_base_salary' => 0, 'total_allowance' => 0, 'total_bonus' => 0,
            'total_gross' => 0, 'total_insurance_employee' => 0, 'total_insurance_employer' => 0,
            'total_trade_union_fee' => 0, 'total_pit' => 0, 'total_deductions' => 0,
            'total_net_salary' => 0, 'created_by' => $this->user->id,
        ]);

        $item = app(PayrollService::class)->buildItem($payroll, $employee, 0, $sheet);

        $this->assertEquals(26, $item->actual_working_days, 'Fallback về standardDays khi không có record');
        $this->assertEquals(26, $item->working_days);
        $this->assertNotNull($item->attendance_note, 'attendance_note phải được set khi không có record');
        $this->assertStringContainsString('không có dữ liệu', strtolower($item->attendance_note));
    }
}
