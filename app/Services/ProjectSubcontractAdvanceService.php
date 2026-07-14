<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\BankAccount;
use App\Models\ProjectSubcontract;
use App\Models\ProjectSubcontractAdvance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectSubcontractAdvanceService
{
    public function __construct(private AccountingService $accounting) {}

    /**
     * Tạm ứng nhà thầu phụ: Nợ {payableAccount} / Có {quỹ tiền mặt/ngân hàng}.
     */
    public function create(ProjectSubcontract $subcontract, array $data): ProjectSubcontractAdvance
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Số tiền tạm ứng phải lớn hơn 0.');
        }

        $date       = Carbon::parse($data['advance_date']);
        $debitAcct  = $subcontract->payableAccount();
        $creditAcct = $this->resolveCreditAccount($data);

        return DB::transaction(function () use ($subcontract, $data, $amount, $date, $debitAcct, $creditAcct) {
            $description = "Tạm ứng HĐ {$subcontract->contract_no}: {$subcontract->contractor_name}";

            $je = $this->accounting->post(
                description: $description,
                date: $date,
                lines: [
                    ['account' => $debitAcct, 'debit' => $amount, 'credit' => 0, 'description' => $description, 'project_id' => $subcontract->project_id],
                    ['account' => $creditAcct, 'debit' => 0, 'credit' => $amount, 'description' => $description, 'project_id' => $subcontract->project_id],
                ],
                referenceType: ProjectSubcontractAdvance::class,
                referenceId: null,
                isAuto: false,
            );

            $advance = ProjectSubcontractAdvance::create([
                'subcontract_id'   => $subcontract->id,
                'project_id'       => $subcontract->project_id,
                'advance_date'     => $date,
                'amount'           => $amount,
                'payment_method'   => $data['payment_method'],
                'fund_id'          => $data['fund_id'] ?? null,
                'bank_account_id'  => $data['bank_account_id'] ?? null,
                'debit_account'    => $debitAcct,
                'credit_account'   => $creditAcct,
                'journal_entry_id' => $je->id,
                'status'           => 'posted',
                'notes'            => $data['notes'] ?? null,
                'created_by'       => auth()->id(),
            ]);

            $je->update(['reference_id' => $advance->id]);

            $subcontract->update(['advance_amount' => (float) $subcontract->advance_amount + $amount]);

            activity()
                ->performedOn($subcontract->project)
                ->causedBy(auth()->user())
                ->withProperties(['subcontract_id' => $subcontract->id, 'advance_id' => $advance->id, 'amount' => $amount])
                ->log('Tạm ứng hợp đồng khoán');

            return $advance;
        });
    }

    public function cancel(ProjectSubcontractAdvance $advance, string $reason): void
    {
        if ($advance->status !== 'posted') {
            throw new \RuntimeException('Chỉ có thể hủy tạm ứng đang ở trạng thái đã hạch toán.');
        }

        DB::transaction(function () use ($advance, $reason) {
            $je = $advance->journalEntry;
            if ($je && $je->status === 'posted') {
                $this->accounting->reverse($je, "Hủy tạm ứng: {$reason}", now());
            }

            $advance->update([
                'status'        => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by'  => auth()->id(),
                'cancelled_at'  => now(),
            ]);

            $subcontract = $advance->subcontract;
            $subcontract->update([
                'advance_amount' => max(0, (float) $subcontract->advance_amount - (float) $advance->amount),
            ]);

            activity()
                ->performedOn($subcontract->project)
                ->causedBy(auth()->user())
                ->withProperties(['advance_id' => $advance->id, 'reason' => $reason])
                ->log('Hủy tạm ứng hợp đồng khoán');
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
