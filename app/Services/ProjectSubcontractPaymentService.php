<?php

namespace App\Services;

use App\Enums\SubcontractorType;
use App\Models\BankAccount;
use App\Models\Fund;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectSubcontractPaymentService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Thanh toán công nợ nhà thầu phụ: Nợ {payableAccount} (gross) / Có {quỹ tiền mặt/ngân hàng} (net nếu có khấu trừ TNCN) [/ Có 3335].
     * Khấu trừ TNCN chỉ áp dụng cho đội nhóm/cá nhân (không hóa đơn) — company đã có hóa đơn VAT, không khấu trừ theo luồng này.
     */
    public function create(ProjectSubcontract $subcontract, array $data): ProjectSubcontractPayment
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Số tiền thanh toán phải lớn hơn 0.');
        }

        $remaining = $subcontract->amountDue();
        if ($amount > $remaining + 0.01) {
            throw new \InvalidArgumentException("Số tiền thanh toán ({$amount}) vượt quá số còn phải trả ({$remaining}).");
        }

        $pitEnabled = (bool) ($data['pit_withholding_enabled'] ?? false) && $subcontract->contractor_type !== SubcontractorType::Company;
        $pitAmount  = $pitEnabled ? round($amount * (float) ($data['pit_rate'] ?? 0) / 100, 2) : 0;

        if ($pitAmount >= $amount) {
            throw new \InvalidArgumentException('Số tiền khấu trừ TNCN không được lớn hơn hoặc bằng số tiền thanh toán.');
        }

        $date       = Carbon::parse($data['payment_date']);
        $debitAcct  = $subcontract->payableAccount();
        $creditAcct = $this->resolveCreditAccount($data);

        return DB::transaction(function () use ($subcontract, $data, $amount, $date, $debitAcct, $creditAcct, $pitEnabled, $pitAmount) {
            $description = "Thanh toán HĐ {$subcontract->contract_no}: {$subcontract->contractor_name}";

            $lines = [
                ['account' => $debitAcct, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'project_id' => $subcontract->project_id],
            ];
            if ($pitAmount > 0) {
                $netAmount = $amount - $pitAmount;
                $lines[] = ['account' => $creditAcct, 'debit' => 0, 'credit' => $netAmount, 'description' => $description . ' (thực trả)', 'project_id' => $subcontract->project_id];
                $lines[] = ['account' => '3335', 'debit' => 0, 'credit' => $pitAmount, 'description' => 'Thuế TNCN khấu trừ — ' . $description, 'project_id' => $subcontract->project_id];
            } else {
                $lines[] = ['account' => $creditAcct, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'project_id' => $subcontract->project_id];
            }

            $je = $this->accounting->post(
                description: $description,
                date: $date,
                lines: $lines,
                referenceType: ProjectSubcontractPayment::class,
                referenceId: null,
                isAuto: false,
            );

            $payment = ProjectSubcontractPayment::create([
                'subcontract_id'    => $subcontract->id,
                'project_id'        => $subcontract->project_id,
                'payment_date'      => $date,
                'amount'            => $amount,
                'payment_method'    => $data['payment_method'],
                'fund_id'           => $data['fund_id'] ?? null,
                'bank_account_id'   => $data['bank_account_id'] ?? null,
                'cash_voucher_id'   => $data['cash_voucher_id'] ?? null,
                'bank_transaction_id' => $data['bank_transaction_id'] ?? null,
                'journal_entry_id'  => $je->id,
                'pit_withholding_enabled' => $pitEnabled,
                'pit_rate'          => $pitEnabled ? ($data['pit_rate'] ?? null) : null,
                'pit_amount'        => $pitAmount,
                'status'            => 'posted',
                'notes'             => $data['notes'] ?? null,
                'created_by'        => auth()->id(),
            ]);

            $je->update(['reference_id' => $payment->id]);

            activity()
                ->performedOn($subcontract->project)
                ->causedBy(auth()->user())
                ->withProperties(['subcontract_id' => $subcontract->id, 'payment_id' => $payment->id, 'amount' => $amount])
                ->log('Thanh toán hợp đồng khoán');

            return $payment;
        });
    }

    public function cancel(ProjectSubcontractPayment $payment, string $reason): void
    {
        if ($payment->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể hủy thanh toán đang ở trạng thái đã hạch toán.');
        }

        DB::transaction(function () use ($payment, $reason) {
            $je = $payment->journalEntry;
            if ($je && $je->status === 'posted') {
                $this->accounting->reverse($je, "Hủy thanh toán: {$reason}", now());
            }

            $payment->update([
                'status'        => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by'  => auth()->id(),
                'cancelled_at'  => now(),
            ]);

            activity()
                ->performedOn($payment->subcontract->project)
                ->causedBy(auth()->user())
                ->withProperties(['payment_id' => $payment->id, 'reason' => $reason])
                ->log('Hủy thanh toán hợp đồng khoán');
        });
    }

    private function resolveCreditAccount(array $data): string
    {
        if ($data['payment_method'] === 'bank') {
            $bankAccount = isset($data['bank_account_id']) ? BankAccount::find($data['bank_account_id']) : null;
            return $bankAccount?->account_code ?? '1121';
        }

        $fund = isset($data['fund_id']) ? Fund::find($data['fund_id']) : null;
        return $fund?->account_code ?? '1111';
    }
}
