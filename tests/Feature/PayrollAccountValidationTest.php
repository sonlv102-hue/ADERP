<?php

namespace Tests\Feature;

use App\Enums\PayrollStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test bắt buộc — rủi ro PayrollService bank account validation và confirmPayroll silent fail.
 *
 * Các test cases:
 *   PA1: bank account không có account_code → payEmployeeSalary fail rõ ràng
 *   PA2: bank account dùng TK tổng hợp (112, is_detail=false) → payEmployeeSalary fail rõ ràng
 *   PA3: bank account dùng TK chi tiết (1121, is_detail=true) → payEmployeeSalary tạo JE thành công
 *   PA4: JE post fail (kỳ đóng) → payroll không chuyển sang confirmed (transaction rollback)
 *   PA5: confirmPayroll thành công → payroll.status = confirmed VÀ JE tồn tại (không journal_entry null)
 *   PA6: API trả lỗi rõ thay vì silent fail khi bank account không hợp lệ
 */
class PayrollAccountValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        // Payroll routes dùng middleware can:accounting.view
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view']);
        $this->user->givePermissionTo('accounting.view');
        $this->actingAs($this->user);

        // Migration 2026_06_06_900045 đã seed: '334','6422','3383','3384','3385','3382' là is_detail=true
        // Seed '1121' (leaf) cho test PA3/PA5
        AccountCode::firstOrCreate(['code' => '1121'], [
            'name' => 'Tiền gửi VND', 'type' => 'asset', 'normal_balance' => 'debit',
            'parent_code' => '112', 'level' => 4, 'is_detail' => true, 'is_active' => true,
        ]);

        $this->employee = Employee::create([
            'code'             => 'NV-TEST',
            'name'             => 'Nhân viên test',
            'status'           => 'active',
            'base_salary'      => 10_000_000,
            'allowance'        => 0,
            'insurance_subject' => false,
            'standard_days'    => 26,
            'created_by'       => $this->user->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createLockedSheet(string $period): AttendanceSheet
    {
        $sheet = AttendanceSheet::create([
            'code'       => 'CC-' . str_replace('-', '', $period),
            'period'     => $period,
            'status'     => 'locked',
            'created_by' => $this->user->id,
        ]);
        AttendanceRecord::create([
            'attendance_sheet_id' => $sheet->id,
            'employee_id'         => $this->employee->id,
            'days'                => '{}',
            'cong'                => 26,
            'nghi_huong_luong'    => 0,
            'nghi_khong_luong'    => 0,
            'ot'                  => 0,
            'tong'                => 26,
        ]);
        return $sheet;
    }

    /** Tạo payroll confirmed cho period, trả về [payroll, payrollItem] */
    private function createConfirmedPayroll(string $period): array
    {
        $this->createLockedSheet($period);
        $this->post(route('accounting.payrolls.store'), ['period' => $period]);
        $payroll = Payroll::where('period', $period)->firstOrFail();
        $this->post(route('accounting.payrolls.confirm', $payroll->id));
        $payroll->refresh();
        return [$payroll, $payroll->items->first()];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PA1: bank account không có account_code → fail với thông báo rõ ràng
    // ─────────────────────────────────────────────────────────────────────────

    public function test_PA1_payEmployee_fails_when_bank_account_has_no_account_code(): void
    {
        [$payroll, $item] = $this->createConfirmedPayroll('2026-05');
        $this->assertEquals(PayrollStatus::Confirmed->value, $payroll->status->value,
            'Payroll phải confirmed trước khi test payEmployee');

        // account_code='' (chuỗi rỗng) mô phỏng bank account chưa cấu hình TK kế toán
        $bank = BankAccount::create([
            'name'           => 'Bank không có TK kế toán',
            'bank_name'      => 'Test Bank',
            'account_number' => '000000001',
            'account_code'   => '',
            'is_active'      => true,
        ]);

        $response = $this->post(
            route('accounting.payrolls.items.pay', [$payroll->id, $item->id]),
            ['bank_account_id' => $bank->id]
        );

        // Phải trả lỗi — không phải 500 hay 200 silent success
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'chưa cấu hình tài khoản kế toán',
            session('error'),
            'Thông báo lỗi phải đề cập đến thiếu cấu hình tài khoản kế toán'
        );

        // PayrollItem không được chuyển sang Paid
        $item->refresh();
        $this->assertNotEquals('paid', $item->status->value, 'Item không được paid khi bank account không hợp lệ');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PA2: bank account dùng TK tổng hợp (112, is_detail=false) → fail rõ ràng
    // ─────────────────────────────────────────────────────────────────────────

    public function test_PA2_payEmployee_fails_when_bank_account_uses_parent_account(): void
    {
        [$payroll, $item] = $this->createConfirmedPayroll('2026-05');

        // '112' seeded by migration as is_detail=false (parent)
        $bank = BankAccount::create([
            'name'           => 'Bank dùng TK cha',
            'bank_name'      => 'Test Bank',
            'account_number' => '000000002',
            'account_code'   => '112',
            'is_active'      => true,
        ]);

        $response = $this->post(
            route('accounting.payrolls.items.pay', [$payroll->id, $item->id]),
            ['bank_account_id' => $bank->id]
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'tổng hợp',
            session('error'),
            'Thông báo lỗi phải đề cập đến tài khoản tổng hợp'
        );

        $item->refresh();
        $this->assertNotEquals('paid', $item->status->value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PA3: bank account dùng TK chi tiết (1121) → payEmployeeSalary tạo JE thành công
    // ─────────────────────────────────────────────────────────────────────────

    public function test_PA3_payEmployee_succeeds_with_leaf_account(): void
    {
        [$payroll, $item] = $this->createConfirmedPayroll('2026-05');

        $bank = BankAccount::create([
            'name'           => 'Bank hợp lệ',
            'bank_name'      => 'Test Bank',
            'account_number' => '000000003',
            'account_code'   => '1121',
            'is_active'      => true,
        ]);

        $response = $this->post(
            route('accounting.payrolls.items.pay', [$payroll->id, $item->id]),
            ['bank_account_id' => $bank->id]
        );

        $response->assertRedirect();
        $response->assertSessionMissing('error');

        $item->refresh();
        $this->assertEquals('paid', $item->status->value, 'Item phải được đánh dấu Paid');
        $this->assertNotNull($item->salary_journal_entry_id, 'salary_journal_entry_id không được null');
        $this->assertNotNull(JournalEntry::find($item->salary_journal_entry_id), 'JE phải tồn tại');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PA4: JE post fail (kỳ kế toán đóng) → payroll KHÔNG chuyển sang confirmed
    // ─────────────────────────────────────────────────────────────────────────

    public function test_PA4_confirm_payroll_rolls_back_when_period_is_closed(): void
    {
        $period = '2026-07';
        $this->createLockedSheet($period);

        // Tạo kỳ đã đóng cho payroll period
        AccountingPeriod::create(['year' => 2026, 'month' => 7, 'status' => 'closed']);

        $this->post(route('accounting.payrolls.store'), ['period' => $period]);
        $payroll = Payroll::where('period', $period)->firstOrFail();

        $response = $this->post(route('accounting.payrolls.confirm', $payroll->id));

        // Controller phải trả lỗi (không silent success)
        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Payroll phải vẫn ở trạng thái draft
        $payroll->refresh();
        $this->assertEquals(
            PayrollStatus::Draft->value,
            $payroll->status->value,
            'Payroll phải vẫn ở trạng thái Draft khi JE không tạo được (kỳ đóng)'
        );

        // Không được có JE nào linked
        $je = JournalEntry::where('reference_type', 'payroll')
            ->where('reference_id', $payroll->id)
            ->first();
        $this->assertNull($je, 'Không được có JE khi confirm thất bại');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PA5: confirmPayroll thành công → payroll confirmed VÀ JE tồn tại (không null)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_PA5_confirm_payroll_creates_journal_entry(): void
    {
        $period = '2026-05';
        $this->createLockedSheet($period);

        $this->post(route('accounting.payrolls.store'), ['period' => $period]);
        $payroll = Payroll::where('period', $period)->firstOrFail();

        $response = $this->post(route('accounting.payrolls.confirm', $payroll->id));
        $response->assertRedirect();

        $payroll->refresh();
        $this->assertEquals(PayrollStatus::Confirmed->value, $payroll->status->value,
            'Payroll phải confirmed sau khi xác nhận thành công');

        $je = JournalEntry::where('reference_type', 'payroll')
            ->where('reference_id', $payroll->id)
            ->first();

        // Phải có JE — không được null (không silent fail như tryPost cũ)
        $this->assertNotNull($je, 'Journal entry PHẢI được tạo khi confirmPayroll thành công');
        $this->assertEquals('posted', $je->status, 'JE phải có status=posted');
        $this->assertEquals('payroll', $je->reference_type);
        $this->assertEquals('payroll_confirm', $je->source_type);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PA6: API response trả lỗi rõ ràng (không silent fail) khi bank không hợp lệ
    // ─────────────────────────────────────────────────────────────────────────

    public function test_PA6_payEmployee_returns_error_in_session_not_silent_fail(): void
    {
        [$payroll, $item] = $this->createConfirmedPayroll('2026-05');

        $bank = BankAccount::create([
            'name'           => 'Bank empty code',
            'bank_name'      => 'Test Bank',
            'account_number' => '000000006',
            'account_code'   => '',
            'is_active'      => true,
        ]);

        $response = $this->post(
            route('accounting.payrolls.items.pay', [$payroll->id, $item->id]),
            ['bank_account_id' => $bank->id]
        );

        // Không được trả 500 (exception không bị handle)
        $response->assertStatus(302);

        // Session phải có 'error' key (không phải success, không phải rỗng)
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');

        // PayrollItem vẫn unpaid
        $item->refresh();
        $this->assertNotEquals('paid', $item->status->value);
        $this->assertNull($item->salary_journal_entry_id);
    }
}
