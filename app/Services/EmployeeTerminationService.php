<?php

namespace App\Services;

use App\Enums\AttendanceSheetStatus;
use App\Enums\EmployeeStatus;
use App\Enums\PayrollItemStatus;
use App\Enums\PayrollStatus;
use App\Models\AccountingPeriod;
use App\Models\AttendanceSheet;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EmployeeTerminationService
{
    public function __construct(private PayrollService $payrollService) {}

    private const LOCKED_PERIOD_WARNING =
        'Ngày thôi việc nằm trong kỳ dữ liệu đã khóa. Việc ghi nhận thôi việc không làm '
        . 'thay đổi các bảng lương/chứng từ đã khóa.';

    /**
     * Ghi nhận nhân viên thôi việc.
     *
     * @param  array{termination_date:string, termination_reason?:?string, termination_note?:?string,
     *               termination_decision_no?:?string, termination_decision_date?:?string}  $data
     * @return string|null  cảnh báo (nếu ngày thôi việc rơi vào kỳ đã khóa)
     */
    public function terminate(Employee $employee, array $data): ?string
    {
        if (!$employee->status->isWorking()) {
            throw new RuntimeException('Nhân viên đã ở trạng thái thôi việc.');
        }

        $date = $this->parseDate($data['termination_date'] ?? null);
        if (!$date) {
            throw new RuntimeException('Ngày thôi việc không hợp lệ.');
        }

        $oldStatus = $employee->status->value;

        DB::transaction(function () use ($employee, $data, $date) {
            $employee->update([
                'status'                    => EmployeeStatus::Resigned,
                'termination_date'          => $date->toDateString(),
                'termination_reason'        => $data['termination_reason'] ?? null,
                'termination_note'          => $data['termination_note'] ?? null,
                'termination_decision_no'   => $data['termination_decision_no'] ?? null,
                'termination_decision_date' => $this->parseDate($data['termination_decision_date'] ?? null)?->toDateString(),
                'terminated_by'             => auth()->id(),
                'terminated_at'             => now(),
            ]);

            $this->pruneFuturePayrollDrafts($employee, $date);
        });

        $this->payrollService->syncEmployeeToDraftPayrolls($employee->fresh());

        activity()
            ->causedBy(auth()->user())
            ->performedOn($employee)
            ->withProperties([
                'action'           => 'TERMINATE',
                'old_status'       => $oldStatus,
                'new_status'       => EmployeeStatus::Resigned->value,
                'termination_date' => $date->toDateString(),
                'reason'           => $data['termination_reason'] ?? null,
            ])
            ->log('terminate');

        return $this->lockedPeriodWarning($date);
    }

    public function cancelTermination(Employee $employee, string $reason): void
    {
        if ($employee->status->isWorking() || !$employee->termination_date) {
            throw new RuntimeException('Nhân viên không ở trạng thái đã thôi việc.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Phải nhập lý do hủy thôi việc.');
        }

        $prevDate = $employee->termination_date?->toDateString();

        DB::transaction(function () use ($employee) {
            $employee->update([
                'status'                    => EmployeeStatus::Active,
                'termination_date'          => null,
                'termination_reason'        => null,
                'termination_note'          => null,
                'termination_decision_no'   => null,
                'termination_decision_date' => null,
                'terminated_by'             => null,
                'terminated_at'             => null,
            ]);
        });

        $this->payrollService->syncEmployeeToDraftPayrolls($employee->fresh());

        activity()
            ->causedBy(auth()->user())
            ->performedOn($employee)
            ->withProperties([
                'action'             => 'CANCEL_TERMINATION',
                'reason'             => $reason,
                'restored_from_date' => $prevDate,
                'new_status'         => EmployeeStatus::Active->value,
            ])
            ->log('cancel_termination');
    }

    /**
     * Gỡ nhân viên khỏi các dòng bảng lương NHÁP (chưa khóa) của các kỳ SAU tháng thôi việc.
     * Không đụng bảng lương đã xác nhận/khóa, không đụng dòng đã thanh toán.
     */
    private function pruneFuturePayrollDrafts(Employee $employee, Carbon $date): void
    {
        $termMonth = $date->format('Y-m');

        $items = PayrollItem::where('employee_id', $employee->id)
            ->where('status', PayrollItemStatus::Pending)
            ->whereHas('payroll', fn ($q) => $q
                ->where('status', PayrollStatus::Draft)
                ->where('is_locked', false)
                ->where('period', '>', $termMonth))
            ->with('payroll')
            ->get();

        $affected = [];
        foreach ($items as $item) {
            $affected[$item->payroll_id] = $item->payroll;
            $item->delete();
        }
        foreach ($affected as $payroll) {
            $this->payrollService->recalculateTotals($payroll);
        }
    }

    private function lockedPeriodWarning(Carbon $date): ?string
    {
        $period = $date->format('Y-m');

        $accountingLocked = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->whereIn('status', ['closed', 'locked'])
            ->exists();

        $payrollLocked = Payroll::where('period', $period)
            ->where(fn ($q) => $q->where('is_locked', true)->orWhere('status', '!=', PayrollStatus::Draft->value))
            ->exists();

        $attendanceLocked = AttendanceSheet::where('period', $period)
            ->where('status', AttendanceSheetStatus::Locked->value)
            ->exists();

        return ($accountingLocked || $payrollLocked || $attendanceLocked)
            ? self::LOCKED_PERIOD_WARNING
            : null;
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (!$raw) {
            return null;
        }
        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
