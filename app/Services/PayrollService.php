<?php

namespace App\Services;

use App\Enums\PayrollStatus;
use App\Enums\PayrollItemStatus;
use App\Enums\CashVoucherType;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\CashVoucher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollService
{
    public function __construct(private CashVoucherService $cashVoucherService) {}

    /**
     * Create a new payroll for the period and populate active users
     */
    public function createPayroll(string $period, ?string $notes = null): Payroll
    {
        return DB::transaction(function () use ($period, $notes) {
            $exists = Payroll::where('period', $period)->exists();
            if ($exists) {
                throw new RuntimeException("Bảng lương cho tháng {$period} đã tồn tại.");
            }

            $payroll = Payroll::create([
                'code'              => Payroll::generateCode($period),
                'period'            => $period,
                'status'            => PayrollStatus::Draft,
                'total_base_salary' => 0,
                'total_allowance'   => 0,
                'total_bonus'       => 0,
                'total_deductions'  => 0,
                'total_net_salary'  => 0,
                'created_by'        => auth()->id() ?? 1,
                'notes'             => $notes,
            ]);

            $activeUsers = User::where('is_active', true)->get();

            $totalBase = 0;
            $totalAllowance = 0;
            $totalNet = 0;

            foreach ($activeUsers as $user) {
                $base = $user->base_salary ?? 0;
                $allowance = $user->allowance ?? 0;
                $net = $base + $allowance;

                PayrollItem::create([
                    'payroll_id'  => $payroll->id,
                    'user_id'     => $user->id,
                    'base_salary' => $base,
                    'allowance'   => $allowance,
                    'bonus'       => 0,
                    'deductions'  => 0,
                    'net_salary'  => $net,
                    'status'      => PayrollItemStatus::Pending,
                ]);

                $totalBase += $base;
                $totalAllowance += $allowance;
                $totalNet += $net;
            }

            $payroll->update([
                'total_base_salary' => $totalBase,
                'total_allowance'   => $totalAllowance,
                'total_net_salary'  => $totalNet,
            ]);

            return $payroll;
        });
    }

    /**
     * Confirm a payroll (freeze details)
     */
    public function confirmPayroll(Payroll $payroll): void
    {
        if ($payroll->status !== PayrollStatus::Draft) {
            throw new RuntimeException("Chỉ có thể xác nhận bảng lương ở trạng thái nháp.");
        }

        $payroll->update(['status' => PayrollStatus::Confirmed]);
    }

    /**
     * Pay salary to an individual employee and automatically create a cash voucher (payment)
     */
    public function payEmployeeSalary(PayrollItem $item, int $fundId): void
    {
        if ($item->status === PayrollItemStatus::Paid) {
            throw new RuntimeException("Dòng lương này đã được thanh toán.");
        }

        $payroll = $item->payroll;
        if ($payroll->status !== PayrollStatus::Confirmed) {
            throw new RuntimeException("Chỉ có thể thanh toán khi bảng lương đã được xác nhận.");
        }

        DB::transaction(function () use ($item, $payroll, $fundId) {
            // Create CashVoucher as Payment in Draft
            $voucher = CashVoucher::create([
                'code'         => CashVoucher::generateCode(CashVoucherType::Payment),
                'type'         => 'payment',
                'status'       => 'draft',
                'fund_id'      => $fundId,
                'amount'       => $item->net_salary,
                'voucher_date' => now(),
                'counterparty' => $item->user->name,
                'description'  => "Chi trả lương nhân viên {$item->user->name} tháng {$payroll->period}",
                'created_by'   => auth()->id() ?? $payroll->created_by,
            ]);

            // Confirm via CashVoucherService to deduct fund
            $this->cashVoucherService->confirm($voucher);

            // Update PayrollItem status and link CashVoucher
            $item->update([
                'status'          => PayrollItemStatus::Paid,
                'paid_at'         => now(),
                'cash_voucher_id' => $voucher->id,
            ]);

            // Check if all items in payroll are paid, then mark payroll as paid
            $allPaid = $payroll->items()->where('status', '!=', 'paid')->count() === 0;
            if ($allPaid) {
                $payroll->update(['status' => PayrollStatus::Paid]);
            }
        });
    }

    /**
     * Recalculate totals for a draft payroll
     */
    public function recalculateTotals(Payroll $payroll): void
    {
        $totals = $payroll->items()
            ->selectRaw('SUM(base_salary) as base, SUM(allowance) as allowance, SUM(bonus) as bonus, SUM(deductions) as deductions, SUM(net_salary) as net')
            ->first();

        $payroll->update([
            'total_base_salary' => $totals->base ?? 0,
            'total_allowance'   => $totals->allowance ?? 0,
            'total_bonus'       => $totals->bonus ?? 0,
            'total_deductions'  => $totals->deductions ?? 0,
            'total_net_salary'  => $totals->net ?? 0,
        ]);
    }
}
