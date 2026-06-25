<?php

namespace App\Services;

use App\Enums\BankTransactionMatchStatus;
use App\Enums\BankTransactionStatus;
use App\Models\BankTransaction;
use App\Models\BankTransactionAllocation;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BankTransactionAllocationService
{
    public function __construct(private AccountingService $accounting) {}

    /** Trả về dữ liệu cần thiết để mở modal đối chiếu. */
    public function getReconcileData(BankTransaction $tx, ?string $partyType = null, ?int $partyId = null): array
    {
        $party = $this->resolveParty($tx, $partyType, $partyId);
        $documents = $this->loadDocuments($tx, $party);
        $alreadyAllocated = (float) BankTransactionAllocation::where('bank_transaction_id', $tx->id)
            ->where('status', 'active')->sum('allocated_amount');

        return [
            'party'            => $party,
            'documents'        => $documents,
            'tx_amount'        => (float) max($tx->debit, $tx->credit),
            'already_allocated' => $alreadyAllocated,
            'is_credit'        => $tx->credit > 0,
        ];
    }

    /** Tạo phân bổ + bút toán cho một giao dịch. Tất cả phân bổ trong 1 lần gọi. */
    public function allocate(BankTransaction $tx, array $party, array $allocations): JournalEntry
    {
        $existing = BankTransactionAllocation::where('bank_transaction_id', $tx->id)
            ->where('status', 'active')->count();
        if ($existing > 0) {
            throw new \RuntimeException('Giao dịch đã có phân bổ. Hủy đối chiếu trước khi phân bổ lại.');
        }

        $txAmount    = (float) max($tx->debit, $tx->credit);
        $allocTotal  = collect($allocations)->sum('amount');
        if ($allocTotal > $txAmount) {
            throw new \RuntimeException('Tổng phân bổ vượt quá số tiền giao dịch.');
        }
        if ($allocTotal <= 0) {
            throw new \RuntimeException('Phải phân bổ ít nhất một chứng từ.');
        }

        return DB::transaction(function () use ($tx, $party, $allocations, $allocTotal, $txAmount) {
            $isCredit = $tx->credit > 0;
            $bankTk   = $tx->bankAccount->account_code;

            // Bank line
            $jeLines = [[
                'account'      => $bankTk,
                'debit'        => $isCredit ? (int) $allocTotal : 0,
                'credit'       => $isCredit ? 0 : (int) $allocTotal,
                'description'  => $tx->description,
                'partner_type' => $party['type'],
                'partner_id'   => $party['id'],
            ]];

            // Counterpart lines per allocation
            foreach ($allocations as $a) {
                $jeLines[] = [
                    'account'      => $a['account_code'],
                    'debit'        => $isCredit ? 0 : (int) $a['amount'],
                    'credit'       => $isCredit ? (int) $a['amount'] : 0,
                    'description'  => $a['description'] ?? $tx->description,
                    'partner_type' => $party['type'],
                    'partner_id'   => $party['id'],
                ];
            }

            $je = $this->accounting->post(
                description:   $this->buildJeDescription($tx, $party),
                date:          Carbon::parse($tx->transaction_date),
                lines:         $jeLines,
                referenceType: 'bank_transaction',
                referenceId:   $tx->id,
                isAuto:        false,
            );

            // Create allocation records
            foreach ($allocations as $a) {
                BankTransactionAllocation::create([
                    'bank_transaction_id' => $tx->id,
                    'party_type'          => $party['type'],
                    'party_id'            => $party['id'],
                    'target_type'         => $a['type'],
                    'target_id'           => $a['id'] ?? null,
                    'account_code'        => $a['account_code'],
                    'allocated_amount'    => (int) $a['amount'],
                    'journal_entry_id'    => $je->id,
                    'status'              => 'active',
                    'created_by'          => auth()->id(),
                ]);
            }

            $isFullyAllocated = abs($allocTotal - $txAmount) < 1;
            $tx->update([
                'match_status'       => $isFullyAllocated ? BankTransactionMatchStatus::Posted : BankTransactionMatchStatus::PartiallyMatched,
                'matched_party_type' => $party['type'],
                'matched_party_id'   => $party['id'],
                'journal_entry_id'   => $je->id,
                'status'             => $isFullyAllocated ? BankTransactionStatus::Reconciled : $tx->status,
                'reconciled_at'      => $isFullyAllocated ? now() : null,
                'reconciled_by'      => $isFullyAllocated ? auth()->id() : null,
                'confirmed_by'       => auth()->id(),
                'confirmed_at'       => now(),
            ]);

            return $je;
        });
    }

    /** Hủy toàn bộ phân bổ, đảo JE nếu đã posted. Hỗ trợ cả auto-match flow (không có allocations). */
    public function cancelAllocation(BankTransaction $tx, string $reason = 'Người dùng hủy đối chiếu'): void
    {
        $allocations = BankTransactionAllocation::where('bank_transaction_id', $tx->id)
            ->where('status', 'active')->get();

        if ($allocations->isEmpty()) {
            // Auto-match flow: không có allocation records, nhưng có thể có journal_entry_id
            if (!$tx->journal_entry_id) {
                throw new \RuntimeException('Không có phân bổ hoặc bút toán nào để hủy.');
            }
            DB::transaction(function () use ($tx, $reason) {
                $je = JournalEntry::find($tx->journal_entry_id);
                if ($je && $je->status === 'posted') {
                    $this->accounting->reverse($je, $reason);
                }
                $tx->update([
                    'match_status'     => BankTransactionMatchStatus::Unmatched,
                    'status'           => BankTransactionStatus::Pending,
                    'journal_entry_id' => null,
                    'reconciled_at'    => null,
                    'reconciled_by'    => null,
                ]);
            });
            return;
        }

        DB::transaction(function () use ($tx, $allocations, $reason) {
            $jeIds = $allocations->pluck('journal_entry_id')->unique()->filter();
            foreach ($jeIds as $jeId) {
                $je = JournalEntry::find($jeId);
                if ($je && $je->status === 'posted') {
                    $this->accounting->reverse($je, $reason);
                }
            }

            foreach ($allocations as $a) {
                $a->update([
                    'status'        => 'cancelled',
                    'cancelled_by'  => auth()->id(),
                    'cancelled_at'  => now(),
                    'cancel_reason' => $reason,
                ]);
            }

            $tx->update([
                'match_status'     => BankTransactionMatchStatus::Unmatched,
                'status'           => BankTransactionStatus::Pending,
                'journal_entry_id' => null,
                'reconciled_at'    => null,
                'reconciled_by'    => null,
            ]);
        });
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function resolveParty(BankTransaction $tx, ?string $partyType, ?int $partyId): ?array
    {
        $type = $partyType ?? $tx->matched_party_type;
        $id   = $partyId   ?? $tx->matched_party_id;

        // Nếu chưa có đối tượng (chưa qua auto-match), thử lookup theo số TK đối ứng
        if (!$type && $tx->counterpart_account) {
            $looked = $this->lookupByBankAccount($tx);
            if ($looked) return $looked;
        }

        if (!$type || !$id) return null;

        if ($type === 'customer') {
            $c = Customer::find($id);
            return $c ? ['type' => 'customer', 'id' => $c->id, 'name' => $c->name, 'code' => $c->code,
                         'confidence_score' => ($partyId ? null : $tx->confidence_score)] : null;
        }
        if ($type === 'supplier') {
            $s = Supplier::find($id);
            return $s ? ['type' => 'supplier', 'id' => $s->id, 'name' => $s->name, 'code' => $s->code,
                         'confidence_score' => ($partyId ? null : $tx->confidence_score)] : null;
        }
        return null;
    }

    /** Lookup NCC/KH theo số tài khoản ngân hàng đối ứng. Tiền ra → ưu tiên NCC; tiền vào → ưu tiên KH. */
    private function lookupByBankAccount(BankTransaction $tx): ?array
    {
        $normalized = preg_replace('/\s+/', '', $tx->counterpart_account);
        if (!$normalized) return null;

        if ($tx->debit > 0) {
            // Tiền ra → ưu tiên Nhà cung cấp
            $sba = SupplierBankAccount::where('is_active', true)
                ->where('normalized_account_number', $normalized)->first();
            if ($sba) {
                $s = Supplier::find($sba->supplier_id);
                return $s ? ['type' => 'supplier', 'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'confidence_score' => 95] : null;
            }
        } else {
            // Tiền vào → ưu tiên Khách hàng
            $cba = CustomerBankAccount::where('is_active', true)
                ->where('normalized_account_number', $normalized)->first();
            if ($cba) {
                $c = Customer::find($cba->customer_id);
                return $c ? ['type' => 'customer', 'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'confidence_score' => 95] : null;
            }
        }

        return null;
    }

    private function loadDocuments(BankTransaction $tx, ?array $party): array
    {
        if (!$party) return [];
        return match($party['type']) {
            'customer' => $this->loadCustomerDocuments($party['id'], $tx->id),
            'supplier' => $this->loadSupplierDocuments($party['id'], $tx->id),
            default    => [],
        };
    }

    private function loadCustomerDocuments(int $customerId, int $txId): array
    {
        return Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue'])
            ->orderByDesc('issue_date')
            ->get()
            ->map(function (Invoice $inv) use ($txId) {
                $paid      = (float) DB::table('payments')->where('invoice_id', $inv->id)->sum('amount');
                $allocated = (float) BankTransactionAllocation::where('target_type', 'invoice')
                    ->where('target_id', $inv->id)->where('status', 'active')
                    ->where('bank_transaction_id', '!=', $txId)->sum('allocated_amount');
                $remaining = max(0, (float)$inv->total - $paid - $allocated);
                if ($remaining < 1) return null;
                return [
                    'type'             => 'invoice',
                    'id'               => $inv->id,
                    'code'             => $inv->code,
                    'date'             => $inv->issue_date?->toDateString(),
                    'due_date'         => $inv->due_date?->toDateString(),
                    'description'      => $inv->notes ?? "Hóa đơn {$inv->code}",
                    'account_code'     => Customer::find($customerId)?->receivable_account_code ?? '1311',
                    'total'            => (float) $inv->total,
                    'amount_paid'      => $paid,
                    'amount_allocated' => $allocated,
                    'amount_remaining' => $remaining,
                ];
            })->filter()->values()->toArray();
    }

    private function loadSupplierDocuments(int $supplierId, int $txId): array
    {
        return PurchaseInvoice::where('supplier_id', $supplierId)
            ->whereIn('status', ['valid', 'partial_paid'])
            ->orderByDesc('invoice_date')
            ->get()
            ->map(function (PurchaseInvoice $inv) use ($txId) {
                $paid      = (float) DB::table('purchase_invoice_payments')->where('purchase_invoice_id', $inv->id)->sum('amount');
                $allocated = (float) BankTransactionAllocation::where('target_type', 'purchase_invoice')
                    ->where('target_id', $inv->id)->where('status', 'active')
                    ->where('bank_transaction_id', '!=', $txId)->sum('allocated_amount');
                $remaining = max(0, (float)$inv->total - $paid - $allocated);
                if ($remaining < 1) return null;
                $accountCode = Supplier::find($supplierId)?->payable_account_code ?? '3311';
                return [
                    'type'             => 'purchase_invoice',
                    'id'               => $inv->id,
                    'code'             => $inv->code,
                    'date'             => $inv->invoice_date?->toDateString(),
                    'description'      => "Hóa đơn mua {$inv->code}",
                    'account_code'     => $accountCode,
                    'total'            => (float) $inv->total,
                    'amount_paid'      => $paid,
                    'amount_allocated' => $allocated,
                    'amount_remaining' => $remaining,
                ];
            })->filter()->values()->toArray();
    }

    private function buildJeDescription(BankTransaction $tx, array $party): string
    {
        $dir = $tx->credit > 0 ? 'Thu' : 'Chi';
        return "{$dir} TK ngân hàng — {$party['name']}: {$tx->description}";
    }
}
