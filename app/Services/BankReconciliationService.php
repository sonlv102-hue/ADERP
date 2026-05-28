<?php

namespace App\Services;

use App\Enums\BankTransactionStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class BankReconciliationService
{
    public function createTransaction(BankAccount $account, array $data): BankTransaction
    {
        return DB::transaction(function () use ($account, $data) {
            $tx = $account->transactions()->create([
                'transaction_date' => $data['transaction_date'],
                'value_date'       => $data['value_date'] ?? $data['transaction_date'],
                'description'      => $data['description'],
                'reference'        => $data['reference'] ?? null,
                'debit'            => $data['debit']  ?? 0,
                'credit'           => $data['credit'] ?? 0,
                'status'           => BankTransactionStatus::Pending,
                'created_by'       => auth()->id(),
            ]);

            return $tx;
        });
    }

    public function reconcile(BankTransaction $tx, int $journalEntryId): void
    {
        if ($tx->status === BankTransactionStatus::Reconciled) {
            throw new \RuntimeException('Giao dịch đã được đối chiếu.');
        }

        $je = JournalEntry::where('status', 'posted')->findOrFail($journalEntryId);

        $tx->update([
            'status'          => BankTransactionStatus::Reconciled,
            'journal_entry_id'=> $je->id,
            'reconciled_at'   => now(),
            'reconciled_by'   => auth()->id(),
        ]);
    }

    public function unreconcile(BankTransaction $tx): void
    {
        if ($tx->status !== BankTransactionStatus::Reconciled) {
            throw new \RuntimeException('Giao dịch chưa được đối chiếu.');
        }

        $tx->update([
            'status'           => BankTransactionStatus::Pending,
            'journal_entry_id' => null,
            'reconciled_at'    => null,
            'reconciled_by'    => null,
        ]);
    }
}
