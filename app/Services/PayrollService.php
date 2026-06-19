<?php

namespace App\Services;

use App\Enums\AttendanceSheetStatus;
use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSheet;
use App\Models\CashVoucher;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\AccountingPostingJob;
use App\Models\Setting;
use App\Services\AccountingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayrollService
{
    public function __construct(
        private PitCalculatorService $pit,
        private AccountingService    $accounting,
        private CashVoucherService   $cashVoucher,
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

    public function payEmployeeSalary(PayrollItem $item, Fund $fund, ?string $paymentDate = null, ?float $actualAmount = null): void
    {
        if ($item->status === PayrollItemStatus::Paid) {
            throw new RuntimeException('Dòng lương này đã thanh toán.');
        }

        $payroll = $item->payroll;
        if ($payroll->status !== PayrollStatus::Confirmed) {
            throw new RuntimeException('Chỉ thanh toán khi bảng lương đã xác nhận.');
        }

        $voucherDate = $paymentDate ?? now()->toDateString();

        DB::transaction(function () use ($item, $payroll, $fund, $voucherDate, $actualAmount) {
            $employeeName = $item->employee?->name ?? 'Nhân viên';
            $thucLinh     = max(0, (float) $item->net_salary + (float) ($item->adjustment_amount ?? 0) - (float) ($item->advance ?? 0));
            $net          = (int) round($actualAmount ?? $thucLinh);

            // Tạo phiếu chi liên kết với dòng lương
            $voucher = CashVoucher::create([
                'code'           => CashVoucher::generateCode(CashVoucherType::Payment),
                'type'           => CashVoucherType::Payment,
                'status'         => CashVoucherStatus::Draft,
                'fund_id'        => $fund->id,
                'amount'         => $net,
                'voucher_date'   => $voucherDate,
                'description'    => "Thanh toán lương tháng {$payroll->period} — {$employeeName}",
                'business_type'  => CashVoucherBusinessType::SalaryPayment->value,
                'partner_type'   => 'employee',
                'employee_id'    => $item->employee_id,
                'reference_type' => PayrollItem::class,
                'reference_id'   => $item->id,
                'created_by'     => auth()->id(),
            ]);

            // Ghi sổ: sinh bút toán Dr 3341 / Có fund.account_code, cập nhật số dư quỹ
            $this->cashVoucher->confirm($voucher);

            $je = JournalEntry::where('reference_type', 'cash_voucher')
                ->where('reference_id', $voucher->id)
                ->where('status', 'posted')
                ->first();

            $item->update([
                'status'                  => PayrollItemStatus::Paid,
                'paid_at'                 => now(),
                'cash_voucher_id'         => $voucher->id,
                'salary_journal_entry_id' => $je?->id,
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
             SUM(net_salary) as net,
             SUM(COALESCE(adjustment_amount, 0)) as adj'
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
            'total_net_salary'         => ($t->net ?? 0) + ($t->adj ?? 0),
            'total_adjustment'         => $t->adj          ?? 0,
        ]);
    }

    /**
     * Cập nhật số điều chỉnh cho một dòng lương.
     * Chỉ cho phép khi bảng lương chưa khóa và chưa xác nhận/thanh toán.
     */
    public function updateAdjustment(
        PayrollItem $item,
        float $amount,
        ?string $reason,
        bool $taxable = true
    ): void {
        $payroll = $item->payroll;

        if ($payroll->is_locked) {
            throw new RuntimeException('Bảng lương đã bị khóa, không thể sửa số điều chỉnh.');
        }
        if ($payroll->status->value !== 'draft') {
            throw new RuntimeException(
                'Bảng lương đã xác nhận hoặc đã thanh toán. '
                . 'Cần hủy xác nhận trước, hoặc lập bảng điều chỉnh riêng.'
            );
        }
        if ($amount != 0 && empty($reason)) {
            throw new RuntimeException('Phải nhập lý do khi số điều chỉnh khác 0.');
        }

        $oldAmount = (float) $item->adjustment_amount;
        $item->update([
            'adjustment_amount'   => $amount,
            'adjustment_reason'   => $reason,
            'adjustment_taxable'  => $taxable,
            'adjusted_by'         => auth()->id(),
            'adjusted_at'         => now(),
        ]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($item)
            ->withProperties([
                'old_amount' => $oldAmount,
                'new_amount' => $amount,
                'reason'     => $reason,
                'taxable'    => $taxable,
            ])
            ->log('adjustment_updated');

        $this->recalculateTotals($payroll);
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

    public function syncFromEmployees(Payroll $payroll): void
    {
        if ($payroll->status !== PayrollStatus::Draft) {
            throw new RuntimeException('Chỉ đồng bộ bảng lương ở trạng thái nháp.');
        }

        DB::transaction(function () use ($payroll) {
            $items = $payroll->items()
                ->with('employee')
                ->where('status', PayrollItemStatus::Pending)
                ->get();

            foreach ($items as $item) {
                if (!$item->employee) {
                    continue;
                }

                $employee = $item->employee;

                $base           = (float) ($employee->base_salary              ?? 0);
                $allocResp      = (float) ($employee->allowance_responsibility ?? 0);
                $allocLunch     = (float) ($employee->allowance_lunch          ?? 0);
                $allocPhone     = (float) ($employee->allowance_phone          ?? 0);
                $allocTransport = (float) ($employee->allowance_transport      ?? 0);
                $allocOther     = (float) ($employee->allowance                ?? 0);
                $dependents     = (int)   ($employee->dependents_count  ?? 0);
                $insSubject     = (bool)  ($employee->insurance_subject ?? true);
                $standardDays   = (int)   ($employee->standard_days    ?? 26);

                // Giữ lại các giá trị nhập tay theo kỳ lương
                $allocPerf   = (float) ($item->allowance_performance ?? 0);
                $bonus       = (float) ($item->bonus                 ?? 0);
                $workingDays = (int)   ($item->working_days          ?? $standardDays);

                $bhxhAllowances    = $allocResp + $allocOther;
                $nonBhxhAllowances = $allocLunch + $allocPhone + $allocTransport + $allocPerf + $bonus;

                $bd = $this->pit->breakdown(
                    $base,
                    $bhxhAllowances,
                    $nonBhxhAllowances,
                    $dependents,
                    $insSubject,
                    $workingDays,
                    $standardDays,
                );

                $tradeUnionFee = 0;
                if ($this->isUnionFeeEnabled() && $insSubject && $bd['insurance_base'] > 0) {
                    $tradeUnionFee = (int) round($bd['insurance_base'] * $this->getUnionFeeRate() / 100);
                }

                $item->update([
                    'base_salary'              => $base,
                    'allowance'                => $allocOther,
                    'allowance_responsibility' => $allocResp,
                    'allowance_lunch'          => $allocLunch,
                    'allowance_phone'          => $allocPhone,
                    'allowance_transport'      => $allocTransport,
                    'standard_days'            => $standardDays,
                    'dependents_count'         => $dependents,
                    'insurance_subject'        => $insSubject,
                    'gross_salary'             => $bd['gross_salary'],
                    'insurance_base'           => $bd['insurance_base'],
                    'bhxh_employee'            => $bd['bhxh_employee'],
                    'bhyt_employee'            => $bd['bhyt_employee'],
                    'bhtn_employee'            => $bd['bhtn_employee'],
                    'bhxh_employer'            => $bd['bhxh_employer'],
                    'bhyt_employer'            => $bd['bhyt_employer'],
                    'bhtn_employer'            => $bd['bhtn_employer'],
                    'pit'                      => $bd['pit'],
                    'deductions'               => $bd['ins_employee'] + $bd['pit'],
                    'net_salary'               => $bd['net_salary'],
                    'trade_union_fee'          => $tradeUnionFee,
                ]);
            }

            $this->recalculateTotals($payroll);
        });
    }

    /**
     * Khi hồ sơ nhân viên được cập nhật lương/phụ cấp, tự động recalculate
     * tất cả PayrollItem đang ở draft (chưa xác nhận, chưa khóa) cho nhân viên đó.
     * Giữ lại: bonus, allowance_performance, working_days, advance (nhập tay theo kỳ).
     */
    public function syncEmployeeToDraftPayrolls(Employee $employee): void
    {
        $items = PayrollItem::where('employee_id', $employee->id)
            ->where('status', PayrollItemStatus::Pending)
            ->whereHas('payroll', fn ($q) => $q->where('status', PayrollStatus::Draft)
                                               ->where('is_locked', false))
            ->with('payroll')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $base           = (float) ($employee->base_salary              ?? 0);
        $allocResp      = (float) ($employee->allowance_responsibility ?? 0);
        $allocLunch     = (float) ($employee->allowance_lunch          ?? 0);
        $allocPhone     = (float) ($employee->allowance_phone          ?? 0);
        $allocTransport = (float) ($employee->allowance_transport      ?? 0);
        $allocOther     = (float) ($employee->allowance                ?? 0);
        $dependents     = (int)   ($employee->dependents_count  ?? 0);
        $insSubject     = (bool)  ($employee->insurance_subject ?? true);
        $standardDays   = (int)   ($employee->standard_days    ?? 26);

        $affectedPayrolls = [];

        DB::transaction(function () use ($items, $base, $allocResp, $allocLunch, $allocPhone, $allocTransport, $allocOther, $dependents, $insSubject, $standardDays, &$affectedPayrolls) {
            foreach ($items as $item) {
                $allocPerf   = (float) ($item->allowance_performance ?? 0);
                $bonus       = (float) ($item->bonus                 ?? 0);
                $workingDays = (int)   ($item->working_days          ?? $standardDays);

                $bhxhAllowances    = $allocResp + $allocOther;
                $nonBhxhAllowances = $allocLunch + $allocPhone + $allocTransport + $allocPerf + $bonus;

                $bd = $this->pit->breakdown(
                    $base,
                    $bhxhAllowances,
                    $nonBhxhAllowances,
                    $dependents,
                    $insSubject,
                    $workingDays,
                    $standardDays,
                );

                $tradeUnionFee = 0;
                if ($this->isUnionFeeEnabled() && $insSubject && $bd['insurance_base'] > 0) {
                    $tradeUnionFee = (int) round($bd['insurance_base'] * $this->getUnionFeeRate() / 100);
                }

                $item->update([
                    'base_salary'              => $base,
                    'allowance'                => $allocOther,
                    'allowance_responsibility' => $allocResp,
                    'allowance_lunch'          => $allocLunch,
                    'allowance_phone'          => $allocPhone,
                    'allowance_transport'      => $allocTransport,
                    'standard_days'            => $standardDays,
                    'dependents_count'         => $dependents,
                    'insurance_subject'        => $insSubject,
                    'gross_salary'             => $bd['gross_salary'],
                    'insurance_base'           => $bd['insurance_base'],
                    'bhxh_employee'            => $bd['bhxh_employee'],
                    'bhyt_employee'            => $bd['bhyt_employee'],
                    'bhtn_employee'            => $bd['bhtn_employee'],
                    'bhxh_employer'            => $bd['bhxh_employer'],
                    'bhyt_employer'            => $bd['bhyt_employer'],
                    'bhtn_employer'            => $bd['bhtn_employer'],
                    'pit'                      => $bd['pit'],
                    'deductions'               => $bd['ins_employee'] + $bd['pit'],
                    'net_salary'               => $bd['net_salary'],
                    'trade_union_fee'          => $tradeUnionFee,
                ]);

                $affectedPayrolls[$item->payroll_id] = $item->payroll;
            }

            foreach ($affectedPayrolls as $payroll) {
                $this->recalculateTotals($payroll);
            }
        });
    }

    /** Post journal entry when payroll is confirmed (TT133) */
    private function postPayrollJournalEntry(Payroll $payroll): void
    {
        // Idempotency: nếu JE đã tồn tại (confirm bị retry), không tạo lại
        if (JournalEntry::where('reference_type', 'payroll')
            ->where('reference_id', $payroll->id)
            ->whereIn('status', ['posted', 'draft'])
            ->exists()) {
            return;
        }

        // total_net_salary đã bao gồm adjustment_amount (sau recalculateTotals)
        $net = round((float)$payroll->total_net_salary);
        $pit = round((float)$payroll->total_pit);

        // Tách BHXH/BHYT thành phần NSDLĐ (Cr Dr chi phí) và NLĐ (Cr Dr 3341)
        $sums = $payroll->items()->selectRaw(
            'SUM(bhxh_employer) as bhxh_empl,
             SUM(bhxh_employee) as bhxh_emp,
             SUM(bhyt_employer) as bhyt_empl,
             SUM(bhyt_employee) as bhyt_emp,
             SUM(bhtn_employee + bhtn_employer) as bhtn'
        )->first();

        $bhxhEmpl = round((float)$sums->bhxh_empl);
        $bhxhEmp  = round((float)$sums->bhxh_emp);
        $bhytEmpl = round((float)$sums->bhyt_empl);
        $bhytEmp  = round((float)$sums->bhyt_emp);
        $bhtn     = round((float)$sums->bhtn);

        // KPCĐ: ghi nhận khi (1) kế toán xác nhận rõ (union_fee_include=true)
        // hoặc (2) chưa đặt (null) và cài đặt toàn hệ thống bật union_fee_enabled.
        // Nếu union_fee_include=false, KHÔNG ghi nhận dù cài đặt bật.
        $includeUnionFee = $payroll->union_fee_include === true
            || ($payroll->union_fee_include === null && $this->isUnionFeeEnabled());

        // Tổng chi phí Dr phân theo phòng ban — bao gồm số điều chỉnh
        $deptTotals = $payroll->items()
            ->join('employees', 'payroll_items.employee_id', '=', 'employees.id')
            ->selectRaw(
                'employees.department,
                 SUM(payroll_items.gross_salary
                     + payroll_items.bhxh_employer
                     + payroll_items.bhyt_employer
                     + payroll_items.bhtn_employer'
                . ($includeUnionFee ? ' + payroll_items.trade_union_fee' : '')
                . ' + COALESCE(payroll_items.adjustment_amount, 0)) as total_cost'
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

        // Cr: phải trả người lao động (lương thực nhận = gross - tất cả khấu trừ)
        $lines[] = ['account' => AccountingSettings::get('salary_payable_account', '3341'),  'debit' => 0, 'credit' => $net,  'description' => "Lương thực nhận NLĐ tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        // Cr: thuế TNCN
        if ($pit > 0) {
            $lines[] = ['account' => AccountingSettings::get('pit_payable_account', '3335'), 'debit' => 0, 'credit' => $pit, 'description' => "Thuế TNCN tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: BHXH phải nộp — tách NSDLĐ (33831) và NLĐ (33832)
        if ($bhxhEmpl > 0) {
            $lines[] = ['account' => AccountingSettings::get('bhxh_payable_account', '33831'),          'debit' => 0, 'credit' => $bhxhEmpl, 'description' => "BHXH NSDLĐ tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        if ($bhxhEmp > 0) {
            $lines[] = ['account' => AccountingSettings::get('bhxh_employee_account', '33832'),         'debit' => 0, 'credit' => $bhxhEmp,  'description' => "BHXH NLĐ tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: BHYT phải nộp — tách NSDLĐ (33841) và NLĐ (33842)
        if ($bhytEmpl > 0) {
            $lines[] = ['account' => AccountingSettings::get('bhyt_payable_account', '33841'),          'debit' => 0, 'credit' => $bhytEmpl, 'description' => "BHYT NSDLĐ tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        if ($bhytEmp > 0) {
            $lines[] = ['account' => AccountingSettings::get('bhyt_employee_account', '33842'),         'debit' => 0, 'credit' => $bhytEmp,  'description' => "BHYT NLĐ tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: BHTN phải nộp (NLĐ + NSDLĐ gộp — 3385 là TK chi tiết)
        if ($bhtn > 0) {
            $lines[] = ['account' => AccountingSettings::get('bhtn_payable_account', '3385'),           'debit' => 0, 'credit' => $bhtn, 'description' => "BHTN phải nộp tháng {$payroll->period}", 'sort_order' => $sortOrder++];
        }
        // Cr: KPCĐ phải nộp (NSDLĐ chịu — 33821)
        if ($includeUnionFee) {
            $unionFee = round((float)$payroll->total_trade_union_fee);
            if ($unionFee > 0) {
                $lines[] = ['account' => AccountingSettings::get('union_fee_payable_account', '33821'), 'debit' => 0, 'credit' => $unionFee, 'description' => "KPCĐ phải nộp tháng {$payroll->period}", 'sort_order' => $sortOrder++];
            }
        }

        if (empty($lines)) {
            throw new RuntimeException(
                "Không thể tạo bút toán lương {$payroll->code}: không có dòng chi phí hợp lệ. "
                . "Kiểm tra lại dữ liệu nhân viên và phòng ban."
            );
        }

        // Post trực tiếp — nếu lỗi sẽ throw, transaction trong confirmPayroll sẽ rollback
        $this->accounting->post(
            description: "Bảng lương tháng {$payroll->period} ({$payroll->code})",
            date: \Carbon\Carbon::createFromFormat('Y-m', $payroll->period)->startOfMonth(),
            lines: $lines,
            referenceType: 'payroll',
            referenceId: $payroll->id,
            isAuto: false,
            journalSourceType: 'payroll_confirm',
            fiscalPeriod: $payroll->period,
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
     * Kiểm tra bank account có account_code hợp lệ (non-empty + is_detail=true).
     * Throw RuntimeException rõ ràng thay vì fallback về TK tổng hợp.
     */
    private function validateBankAccountCode(BankAccount $bankAccount): void
    {
        if (empty($bankAccount->account_code)) {
            throw new RuntimeException(
                "Tài khoản ngân hàng '{$bankAccount->name}' ({$bankAccount->account_number}) "
                . "chưa cấu hình tài khoản kế toán. "
                . "Vui lòng cập nhật tài khoản kế toán chi tiết (ví dụ: 1121 — Tiền gửi VND) "
                . "trước khi thanh toán lương."
            );
        }

        $ac = AccountCode::where('code', $bankAccount->account_code)->first();

        if (!$ac) {
            throw new RuntimeException(
                "Tài khoản kế toán '{$bankAccount->account_code}' trên tài khoản ngân hàng "
                . "'{$bankAccount->name}' không tồn tại trong hệ thống. "
                . "Kiểm tra lại danh mục tài khoản."
            );
        }

        if (!$ac->is_detail) {
            throw new RuntimeException(
                "Tài khoản kế toán '{$ac->code}' ({$ac->name}) là tài khoản tổng hợp "
                . "(is_detail=false), không thể hạch toán trực tiếp. "
                . "Vui lòng cấu hình tài khoản chi tiết, ví dụ: 1121 (Tiền gửi VND) "
                . "thay vì 112 (Tiền gửi ngân hàng)."
            );
        }
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
            if (str_contains($dept, $kw)) return AccountingSettings::get('payroll_sales_labor_account', '6421');
        }

        $techKeywords = ['kỹ thuật', 'technical', 'it ', 'công nghệ', 'triển khai', 'dự án', 'thi công'];
        foreach ($techKeywords as $kw) {
            if (str_contains($dept, $kw)) return AccountingSettings::get('payroll_production_labor_account', '627');
        }

        return AccountingSettings::get('payroll_admin_labor_account', '6422');
    }
}
