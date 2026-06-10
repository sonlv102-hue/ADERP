<?php

namespace App\Services;

use App\Enums\AttendanceSheetStatus;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\AccountingPostingJob;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayrollService
{
    public function __construct(
        private PitCalculatorService $pit,
        private AccountingService   $accounting,
    ) {}

    public function createPayroll(string $period, ?string $notes = null): Payroll
    {
        // Bảng chấm công phải được chốt trước khi lập bảng lương
        $sheet = AttendanceSheet::where('period', $period)->first();
        if (!$sheet || $sheet->status !== AttendanceSheetStatus::Locked) {
            throw new RuntimeException(
                "Bảng chấm công tháng {$period} chưa được chốt. Vui lòng chốt bảng chấm công trước khi lập bảng lương."
            );
        }

        return DB::transaction(function () use ($period, $notes, $sheet) {
            if (Payroll::where('period', $period)->exists()) {
                throw new RuntimeException("Bảng lương tháng {$period} đã tồn tại.");
            }

            $payroll = Payroll::create([
                'code'                     => Payroll::generateCode($period),
                'period'                   => $period,
                'status'                   => PayrollStatus::Draft,
                'total_base_salary'        => 0,
                'total_allowance'          => 0,
                'total_bonus'              => 0,
                'total_gross'              => 0,
                'total_insurance_employee' => 0,
                'total_insurance_employer' => 0,
                'total_trade_union_fee'    => 0,
                'total_pit'                => 0,
                'total_deductions'         => 0,
                'total_net_salary'         => 0,
                'created_by'               => auth()->id() ?? throw new RuntimeException('Chưa đăng nhập.'),
                'notes'                    => $notes,
            ]);

            // Lấy từ Cán bộ CNV (employees) đang làm việc — truyền sheet để tránh N+1
            foreach (Employee::whereIn('status', ['active', 'probation'])->orderBy('name')->get() as $employee) {
                $this->buildItem($payroll, $employee, 0, $sheet);
            }

            $this->recalculateTotals($payroll);

            return $payroll;
        });
    }

    public function confirmPayroll(Payroll $payroll): void
    {
        if ($payroll->status !== PayrollStatus::Draft) {
            throw new RuntimeException('Chỉ xác nhận bảng lương ở trạng thái nháp.');
        }

        DB::transaction(function () use ($payroll) {
            $payroll->update(['status' => PayrollStatus::Confirmed]);
            $this->postPayrollJournalEntry($payroll);
        });
    }

    public function unconfirmPayroll(Payroll $payroll): void
    {
        if ($payroll->status !== PayrollStatus::Confirmed) {
            throw new \RuntimeException('Chỉ hủy xác nhận bảng lương ở trạng thái đã xác nhận.');
        }
        if ($payroll->is_locked) {
            throw new \RuntimeException('Bảng lương đã bị khóa, không thể hủy xác nhận.');
        }
        if ($payroll->items()->where('status', PayrollItemStatus::Paid)->exists()) {
            throw new \RuntimeException('Bảng lương đã có nhân viên được thanh toán, không thể hủy xác nhận.');
        }

        DB::transaction(function () use ($payroll) {
            $this->accounting->reverseOrDelete('payroll', $payroll->id, "Hủy xác nhận bảng lương {$payroll->code}");

            AccountingPostingJob::where('source_type', 'payroll')
                ->where('source_id', $payroll->id)
                ->where('posting_type', 'salary')
                ->delete();

            $payroll->update(['status' => PayrollStatus::Draft]);
        });
    }

    public function payEmployeeSalary(PayrollItem $item, int $bankAccountId): void
    {
        if ($item->status === PayrollItemStatus::Paid) {
            throw new RuntimeException('Dòng lương này đã thanh toán.');
        }

        $payroll = $item->payroll;
        if ($payroll->status !== PayrollStatus::Confirmed) {
            throw new RuntimeException('Chỉ thanh toán khi bảng lương đã xác nhận.');
        }

        $bankAccount = BankAccount::findOrFail($bankAccountId);
        $bankTk = $bankAccount->account_code ?: '112';

        DB::transaction(function () use ($item, $payroll, $bankAccount, $bankTk) {
            $employeeName = $item->employee?->name ?? 'Nhân viên';
            $net = round((float)$item->net_salary);

            // Bút toán chi lương: Nợ 334 / Có 112 (chuyển khoản ngân hàng)
            $je = $this->accounting->post(
                description: "Chi lương {$employeeName} tháng {$payroll->period} — CK {$bankAccount->account_number}",
                date: now(),
                lines: [
                    ['account' => '334',   'debit' => $net, 'credit' => 0,   'description' => "Lương {$employeeName} tháng {$payroll->period}"],
                    ['account' => $bankTk, 'debit' => 0,   'credit' => $net, 'description' => "CK lương {$bankAccount->bank_name} - {$bankAccount->account_number}"],
                ],
                referenceType: PayrollItem::class,
                referenceId: $item->id,
                isAuto: false,
            );

            $item->update([
                'status'                  => PayrollItemStatus::Paid,
                'paid_at'                 => now(),
                'salary_journal_entry_id' => $je->id,
            ]);

            $allPaid = $payroll->items()->where('status', '!=', PayrollItemStatus::Paid->value)->count() === 0;
            if ($allPaid) {
                $payroll->update(['status' => PayrollStatus::Paid]);
            }
        });
    }

    public function recalculateTotals(Payroll $payroll): void
    {
        $t = $payroll->items()->selectRaw(
            'SUM(base_salary) as base, SUM(allowance) as allowance, SUM(bonus) as bonus,
             SUM(gross_salary) as gross,
             SUM(bhxh_employee+bhyt_employee+bhtn_employee) as ins_emp,
             SUM(bhxh_employer+bhyt_employer+bhtn_employer) as ins_empl,
             SUM(trade_union_fee) as union_fee,
             SUM(pit) as pit,
             SUM(deductions) as deductions,
             SUM(net_salary) as net'
        )->first();

        $payroll->update([
            'total_base_salary'        => $t->base        ?? 0,
            'total_allowance'          => $t->allowance   ?? 0,
            'total_bonus'              => $t->bonus        ?? 0,
            'total_gross'              => $t->gross        ?? 0,
            'total_insurance_employee' => $t->ins_emp      ?? 0,
            'total_insurance_employer' => $t->ins_empl     ?? 0,
            'total_trade_union_fee'    => $t->union_fee    ?? 0,
            'total_pit'                => $t->pit          ?? 0,
            'total_deductions'         => $t->deductions   ?? 0,
            'total_net_salary'         => $t->net          ?? 0,
        ]);
    }

    /** Rebuild a single PayrollItem from Employee */
    public function buildItem(Payroll $payroll, Employee $employee, float $bonus = 0, ?AttendanceSheet $attendanceSheet = null): PayrollItem
    {
        $base                = (float)($employee->base_salary              ?? 0);
        $allocResp           = (float)($employee->allowance_responsibility ?? 0);
        $allocLunch          = (float)($employee->allowance_lunch          ?? 0);
        $allocPhone          = (float)($employee->allowance_phone          ?? 0);
        $allocTransport      = (float)($employee->allowance_transport      ?? 0);
        $allocOther          = (float)($employee->allowance                ?? 0);
        $dependents          = (int)($employee->dependents_count  ?? 0);
        $insuranceSubject    = (bool)($employee->insurance_subject ?? true);
        $standardDays        = (int)($employee->standard_days    ?? 26);

        // Lấy dữ liệu chấm công (hybrid mode): actual_paid_days = cong + nghi_huong_luong
        $sheet             = $attendanceSheet ?? AttendanceSheet::where('period', $payroll->period)->first();
        $attendanceSheetId = $sheet?->id;
        $actualWorkingDays = $standardDays;
        $paidLeaveDays     = 0;
        $unpaidLeaveDays   = 0;
        $overtimeDays      = 0;
        $attendanceNote    = null;

        if ($sheet && $sheet->status === AttendanceSheetStatus::Locked) {
            $record = AttendanceRecord::where('attendance_sheet_id', $sheet->id)
                ->where('employee_id', $employee->id)
                ->first();

            if ($record) {
                $actualWorkingDays = (int)$record->cong + (int)$record->nghi_huong_luong;
                $paidLeaveDays     = (int)$record->nghi_huong_luong;
                $unpaidLeaveDays   = (int)$record->nghi_khong_luong;
                $overtimeDays      = (int)$record->ot;
            } else {
                Log::warning("PayrollService: Không tìm thấy dữ liệu chấm công cho NV {$employee->code} kỳ {$payroll->period}. Áp dụng standardDays.");
                $attendanceNote = 'Không có dữ liệu chấm công — áp dụng ngày công chuẩn';
            }
        } else {
            $attendanceNote = 'Không có bảng chấm công đã chốt';
        }

        // Phân loại theo Nghị định 158/2025:
        //   BHXH-subject  : PC trách nhiệm/chức vụ (ổn định HĐLĐ) + phụ cấp cố định khác
        //   Non-BHXH      : hỗ trợ ăn trưa, xăng xe, điện thoại (phúc lợi)
        $bhxhAllowances    = $allocResp + $allocOther;                            // tính BHXH
        $nonBhxhAllowances = $allocLunch + $allocPhone + $allocTransport + $bonus; // không BHXH

        $bd = $this->pit->breakdown(
            $base,
            $bhxhAllowances,
            $nonBhxhAllowances,
            $dependents,
            $insuranceSubject,
            $actualWorkingDays,
            $standardDays,
        );

        $deductions = $bd['ins_employee'] + $bd['pit'];

        // KPCĐ: do NSDLĐ chịu, không trừ vào net_salary của NLĐ.
        // Base = insurance_base (tổng quỹ lương làm căn cứ đóng BHXH bắt buộc).
        // Chỉ tính cho NLĐ thuộc diện đóng BHXH bắt buộc (insurance_subject = true).
        $tradeUnionFee = 0;
        if ($this->isUnionFeeEnabled()) {
            if ($insuranceSubject) {
                if ($bd['insurance_base'] > 0) {
                    $tradeUnionFee = (int) round($bd['insurance_base'] * $this->getUnionFeeRate() / 100);
                } else {
                    Log::warning("PayrollService: employee {$employee->code} thuộc diện đóng BHXH nhưng insurance_base = 0. "
                        . 'KPCĐ không thể tính. Kiểm tra lại base_salary của nhân viên này.');
                }
            }
        }

        return $payroll->items()->create([
            'employee_id'              => $employee->id,
            'base_salary'              => $base,
            'allowance'                => $allocOther,
            'allowance_responsibility' => $allocResp,
            'allowance_lunch'          => $allocLunch,
            'allowance_phone'          => $allocPhone,
            'allowance_transport'      => $allocTransport,
            'allowance_performance'    => 0,
            'bonus'                    => $bonus,
            'gross_salary'             => $bd['gross_salary'],
            'insurance_base'           => $bd['insurance_base'],
            'bhxh_employee'            => $bd['bhxh_employee'],
            'bhyt_employee'            => $bd['bhyt_employee'],
            'bhtn_employee'            => $bd['bhtn_employee'],
            'bhxh_employer'            => $bd['bhxh_employer'],
            'bhyt_employer'            => $bd['bhyt_employer'],
            'bhtn_employer'            => $bd['bhtn_employer'],
            'pit'                      => $bd['pit'],
            'dependents_count'         => $dependents,
            'deductions'               => $deductions,
            'net_salary'               => $bd['net_salary'],
            'working_days'             => $actualWorkingDays,
            'standard_days'            => $standardDays,
            'advance'                  => 0,
            'insurance_subject'        => $insuranceSubject,
            'trade_union_fee'          => $tradeUnionFee,
            'attendance_sheet_id'      => $attendanceSheetId,
            'actual_working_days'      => $actualWorkingDays,
            'paid_leave_days'          => $paidLeaveDays,
            'unpaid_leave_days'        => $unpaidLeaveDays,
            'overtime_days'            => $overtimeDays,
            'attendance_note'          => $attendanceNote,
            'status'                   => PayrollItemStatus::Pending,
        ]);
    }

    /** Post journal entry when payroll is confirmed (TT133) */
    private function postPayrollJournalEntry(Payroll $payroll): void
    {
        $net = round((float)$payroll->total_net_salary);
        $pit = round((float)$payroll->total_pit);

        // Tổng BHXH/BHYT/BHTN (cả NLĐ + NSDLĐ gộp vào Cr 338x)
        $sums = $payroll->items()->selectRaw(
            'SUM(bhxh_employee + bhxh_employer) as bhxh,
             SUM(bhyt_employee + bhyt_employer) as bhyt,
             SUM(bhtn_employee + bhtn_employer) as bhtn'
        )->first();

        $bhxh = round((float)$sums->bhxh);
        $bhyt = round((float)$sums->bhyt);
        $bhtn = round((float)$sums->bhtn);

        // Tổng chi phí Dr phân theo phòng ban (gross + employer insurance + KPCĐ)
        $deptTotals = $payroll->items()
            ->join('employees', 'payroll_items.employee_id', '=', 'employees.id')
            ->selectRaw(
                'employees.department,
                 SUM(payroll_items.gross_salary
                     + payroll_items.bhxh_employer
                     + payroll_items.bhyt_employer
                     + payroll_items.bhtn_employer
                     + payroll_items.trade_union_fee) as total_cost'
            )
            ->groupBy('employees.department')
            ->get();

        $lines = [];
        $sortOrder = 1;

        foreach ($deptTotals as $row) {
            $tk = $this->departmentExpenseAccount((string)($row->department ?? ''));
            $amount = round((float)$row->total_cost);
            if ($amount <= 0) continue;

            $lines[] = [
                'account'     => $tk,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => "Chi phí lương {$row->department} tháng {$payroll->period}",
                'sort_order'  => $sortOrder++,
            ];
        }

        // Cr: phải trả người lao động (lương thực nhận)
        $lines[] = ['account' => '334',  'debit' => 0, 'credit' => $net,  'description' => "Lương thực nhận NLĐ tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        // Cr: thuế TNCN (TK 3335)
        if ($pit > 0) {
            $lines[] = ['account' => '3335', 'debit' => 0, 'credit' => $pit, 'description' => "Thuế TNCN tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: BHXH phải nộp (TK 3383) — NLĐ + NSDLĐ
        if ($bhxh > 0) {
            $lines[] = ['account' => '3383', 'debit' => 0, 'credit' => $bhxh, 'description' => "BHXH phải nộp tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: BHYT phải nộp (TK 3384)
        if ($bhyt > 0) {
            $lines[] = ['account' => '3384', 'debit' => 0, 'credit' => $bhyt, 'description' => "BHYT phải nộp tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: BHTN phải nộp (TK 3385)
        if ($bhtn > 0) {
            $lines[] = ['account' => '3385', 'debit' => 0, 'credit' => $bhtn, 'description' => "BHTN phải nộp tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: KPCĐ phải nộp (TK 3382) — do NSDLĐ chịu, base = tổng insurance_base NLĐ đóng BHXH bắt buộc
        $unionFee = round((float)$payroll->total_trade_union_fee);
        if ($unionFee > 0) {
            $lines[] = ['account' => '3382', 'debit' => 0, 'credit' => $unionFee, 'description' => "KPCĐ phải nộp tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }

        if (empty($lines)) {
            Log::warning("PayrollService: no debit lines for payroll #{$payroll->id}");
            return;
        }

        $this->accounting->tryPost(
            description: "Bảng lương tháng {$payroll->period} ({$payroll->code})",
            date: \Carbon\Carbon::createFromFormat('Y-m', $payroll->period)->startOfMonth(),
            lines: $lines,
            sourceType: 'payroll',
            sourceId: $payroll->id,
            postingType: 'salary',
        );
    }

    /**
     * Đọc từ settings: KPCĐ có được bật không?
     * Mặc định: bật (= '1'). Kế toán có thể tắt qua settings nếu công ty được miễn/giảm/tạm dừng.
     * NOTE: SME và "chưa có công đoàn cơ sở" không phải lý do tự động miễn — phải xác nhận rõ.
     */
    private function isUnionFeeEnabled(): bool
    {
        return Setting::get('payroll.union_fee_enabled', '1') === '1';
    }

    /**
     * Tỷ lệ KPCĐ (% trên tổng quỹ lương BHXH bắt buộc).
     * Mặc định: 2% theo quy định hiện hành (Nghị định 191/2013/NĐ-CP).
     * Kế toán có thể điều chỉnh nếu cơ quan chức năng thay đổi tỷ lệ.
     */
    private function getUnionFeeRate(): float
    {
        return (float) Setting::get('payroll.union_fee_rate', '2');
    }

    /**
     * Map tên phòng ban → TK chi phí lương (TT133):
     *   Kinh doanh / Sales → 6421
     *   Kỹ thuật / Technical / IT / Dự án → 627
     *   Còn lại (Quản lý, Kế toán, Hành chính…) → 6422
     */
    private function departmentExpenseAccount(string $department): string
    {
        $dept = mb_strtolower(trim($department));

        $salesKeywords = ['kinh doanh', 'sales', 'bán hàng', 'marketing'];
        foreach ($salesKeywords as $kw) {
            if (str_contains($dept, $kw)) return '6421';
        }

        $techKeywords = ['kỹ thuật', 'technical', 'it ', 'công nghệ', 'triển khai', 'dự án', 'thi công'];
        foreach ($techKeywords as $kw) {
            if (str_contains($dept, $kw)) return '627';
        }

        return '6422'; // mặc định: chi phí quản lý doanh nghiệp
    }
}
