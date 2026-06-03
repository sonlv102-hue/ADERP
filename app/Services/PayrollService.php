<?php

namespace App\Services;

use App\Enums\CashVoucherType;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollStatus;
use App\Models\CashVoucher;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayrollService
{
    public function __construct(
        private CashVoucherService  $cashVoucherService,
        private PitCalculatorService $pit,
        private AccountingService   $accounting,
    ) {}

    public function createPayroll(string $period, ?string $notes = null): Payroll
    {
        return DB::transaction(function () use ($period, $notes) {
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
                'total_pit'                => 0,
                'total_deductions'         => 0,
                'total_net_salary'         => 0,
                'created_by'               => auth()->id() ?? throw new RuntimeException('Chưa đăng nhập.'),
                'notes'                    => $notes,
            ]);

            // Lấy từ Cán bộ CNV (employees) đang làm việc
            foreach (Employee::whereIn('status', ['active', 'probation'])->orderBy('name')->get() as $employee) {
                $this->buildItem($payroll, $employee, 0);
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

    public function payEmployeeSalary(PayrollItem $item, int $fundId): void
    {
        if ($item->status === PayrollItemStatus::Paid) {
            throw new RuntimeException('Dòng lương này đã thanh toán.');
        }

        $payroll = $item->payroll;
        if ($payroll->status !== PayrollStatus::Confirmed) {
            throw new RuntimeException('Chỉ thanh toán khi bảng lương đã xác nhận.');
        }

        DB::transaction(function () use ($item, $payroll, $fundId) {
            $employeeName = $item->employee?->name ?? $item->user?->name ?? 'Nhân viên';
            $voucher = CashVoucher::create([
                'code'         => CashVoucher::generateCode(CashVoucherType::Payment),
                'type'         => 'payment',
                'status'       => 'draft',
                'fund_id'      => $fundId,
                'amount'       => $item->net_salary,
                'voucher_date' => now(),
                'counterparty' => $employeeName,
                'description'  => "Chi lương {$employeeName} tháng {$payroll->period}",
                'created_by'   => auth()->id() ?? throw new RuntimeException('Chưa đăng nhập.'),
            ]);

            $this->cashVoucherService->confirm($voucher);

            $item->update([
                'status'          => PayrollItemStatus::Paid,
                'paid_at'         => now(),
                'cash_voucher_id' => $voucher->id,
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
             SUM(pit) as pit,
             SUM(deductions) as deductions,
             SUM(net_salary) as net'
        )->first();

        $payroll->update([
            'total_base_salary'        => $t->base     ?? 0,
            'total_allowance'          => $t->allowance ?? 0,
            'total_bonus'              => $t->bonus     ?? 0,
            'total_gross'              => $t->gross     ?? 0,
            'total_insurance_employee' => $t->ins_emp   ?? 0,
            'total_insurance_employer' => $t->ins_empl  ?? 0,
            'total_pit'                => $t->pit       ?? 0,
            'total_deductions'         => $t->deductions ?? 0,
            'total_net_salary'         => $t->net       ?? 0,
        ]);
    }

    /** Rebuild a single PayrollItem from Employee */
    public function buildItem(Payroll $payroll, Employee $employee, float $bonus = 0): PayrollItem
    {
        $base                = (float)($employee->base_salary              ?? 0);
        $allocResp           = (float)($employee->allowance_responsibility ?? 0);
        $allocLunch          = (float)($employee->allowance_lunch          ?? 0);
        $allocPhone          = (float)($employee->allowance_phone          ?? 0);
        $allocTransport      = (float)($employee->allowance_transport      ?? 0);
        $allocOther          = (float)($employee->allowance                ?? 0);
        $totalAllowances     = $allocResp + $allocLunch + $allocPhone + $allocTransport + $allocOther;
        $dependents          = (int)($employee->dependents_count  ?? 0);
        $insuranceSubject    = (bool)($employee->insurance_subject ?? true);
        $standardDays        = (int)($employee->standard_days    ?? 26);

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
            $standardDays,
            $standardDays,
        );

        $deductions = $bd['ins_employee'] + $bd['pit'];

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
            'working_days'             => $standardDays,
            'standard_days'            => $standardDays,
            'advance'                  => 0,
            'insurance_subject'        => $insuranceSubject,
            'status'                   => PayrollItemStatus::Pending,
        ]);
    }

    /** Post journal entry when payroll is confirmed */
    private function postPayrollJournalEntry(Payroll $payroll): void
    {
        $gross      = (float)$payroll->total_gross;
        $insEmp     = (float)$payroll->total_insurance_employee;
        $insEmpl    = (float)$payroll->total_insurance_employer;
        $pit        = (float)$payroll->total_pit;
        $net        = (float)$payroll->total_net_salary;

        // BHXH breakdown from items
        $sums = $payroll->items()->selectRaw(
            'SUM(bhxh_employee+bhxh_employer) as bhxh,
             SUM(bhyt_employee+bhyt_employer) as bhyt,
             SUM(bhtn_employee+bhtn_employer) as bhtn'
        )->first();

        $bhxh = round((float)$sums->bhxh);
        $bhyt = round((float)$sums->bhyt);
        $bhtn = round((float)$sums->bhtn);

        $lines = [
            // Dr: chi phí lương (gross + employer insurance)
            ['account_code' => '6421', 'debit' => round($gross + $insEmpl), 'credit' => 0, 'description' => "Chi phí lương tháng {$payroll->period}", 'sort_order' => 1],
            // Cr: phải trả người lao động (net)
            ['account_code' => '334',  'debit' => 0, 'credit' => round($net),  'description' => "Lương net nhân viên", 'sort_order' => 2],
            // Cr: thuế TNCN
            ['account_code' => '3335', 'debit' => 0, 'credit' => round($pit),  'description' => "Thuế TNCN tháng {$payroll->period}", 'sort_order' => 3],
        ];

        if ($bhxh > 0) {
            $lines[] = ['account_code' => '3338', 'debit' => 0, 'credit' => $bhxh, 'description' => "BHXH phải nộp", 'sort_order' => 4];
        }
        if ($bhyt > 0) {
            $lines[] = ['account_code' => '3389', 'debit' => 0, 'credit' => $bhyt, 'description' => "BHYT phải nộp", 'sort_order' => 5];
        }
        if ($bhtn > 0) {
            $lines[] = ['account_code' => '3384', 'debit' => 0, 'credit' => $bhtn, 'description' => "BHTN phải nộp", 'sort_order' => 6];
        }

        try {
            $this->accounting->post(
                description: "Bảng lương tháng {$payroll->period} ({$payroll->code})",
                date: \Carbon\Carbon::createFromFormat('Y-m', $payroll->period)->startOfMonth(),
                lines: $lines,
                referenceType: Payroll::class,
                referenceId: $payroll->id,
                isAuto: true,
            );
        } catch (\Throwable $e) {
            Log::warning("PayrollService: journal entry failed for payroll #{$payroll->id}: " . $e->getMessage());
        }
    }
}
