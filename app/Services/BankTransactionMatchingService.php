<?php

namespace App\Services;

use App\Enums\BankTransactionMatchStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankTransactionMatchingService
{
    private const MIN_CONFIDENCE = 60;

    public function __construct(private AccountingService $accounting) {}

    /** Batch-match tất cả giao dịch 'unmatched' của một tài khoản ngân hàng. */
    public function matchAll(BankAccount $account): array
    {
        $txs = BankTransaction::where('bank_account_id', $account->id)
            ->where('match_status', BankTransactionMatchStatus::Unmatched->value)
            ->get();

        $suggested = 0;
        foreach ($txs as $tx) {
            $this->matchTransaction($tx);
            if ($tx->fresh()->match_status === BankTransactionMatchStatus::Suggested) {
                $suggested++;
            }
        }

        return ['suggested' => $suggested, 'total' => $txs->count()];
    }

    /** Phân tích và lưu đề xuất cho một giao dịch. */
    public function matchTransaction(BankTransaction $tx): void
    {
        if (in_array($tx->match_status?->value, [
            BankTransactionMatchStatus::Confirmed->value,
            BankTransactionMatchStatus::Posted->value,
            BankTransactionMatchStatus::Ignored->value,
        ])) {
            return;
        }

        $candidates = $this->buildCandidates($tx);
        if (empty($candidates)) {
            $tx->update(['match_status' => BankTransactionMatchStatus::Unmatched->value]);
            return;
        }

        // Pick highest-score candidate
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = $candidates[0];

        if ($best['score'] < self::MIN_CONFIDENCE) {
            $tx->update(['match_status' => BankTransactionMatchStatus::Unmatched->value]);
            return;
        }

        $suggestedType = $this->suggestTxType($tx, $best['party_type'], $best['document_type'] ?? null);

        $tx->update([
            'match_status'         => BankTransactionMatchStatus::Suggested->value,
            'matched_party_type'   => $best['party_type'],
            'matched_party_id'     => $best['party_id'],
            'matched_document_type'=> $best['document_type'] ?? null,
            'matched_document_id'  => $best['document_id']   ?? null,
            'confidence_score'     => min($best['score'], 100),
            'suggested_tx_type'    => $suggestedType,
            'match_note'           => $best['note'] ?? null,
        ]);
    }

    /** Kế toán xác nhận (hoặc chỉnh sửa) đề xuất. */
    public function confirmMatch(BankTransaction $tx, array $data): void
    {
        $tx->update([
            'match_status'         => BankTransactionMatchStatus::Confirmed->value,
            'matched_party_type'   => $data['matched_party_type']    ?? $tx->matched_party_type,
            'matched_party_id'     => $data['matched_party_id']      ?? $tx->matched_party_id,
            'matched_document_type'=> $data['matched_document_type'] ?? $tx->matched_document_type,
            'matched_document_id'  => $data['matched_document_id']   ?? $tx->matched_document_id,
            'suggested_tx_type'    => $data['tx_type']               ?? $tx->suggested_tx_type,
            'match_note'           => $data['match_note']            ?? $tx->match_note,
            'confirmed_by'         => auth()->id(),
            'confirmed_at'         => now(),
        ]);
    }

    /** Đánh dấu bỏ qua giao dịch này. */
    public function ignoreMatch(BankTransaction $tx): void
    {
        $tx->update(['match_status' => BankTransactionMatchStatus::Ignored->value]);
    }

    /** Tạo bút toán kế toán cho giao dịch đã confirmed. */
    public function createJournalEntry(BankTransaction $tx): JournalEntry
    {
        if ($tx->match_status === BankTransactionMatchStatus::Posted) {
            throw new \RuntimeException('Giao dịch này đã được hạch toán rồi.');
        }
        if (! in_array($tx->match_status?->value, [
            BankTransactionMatchStatus::Confirmed->value,
            BankTransactionMatchStatus::Suggested->value,
        ])) {
            throw new \RuntimeException('Cần xác nhận giao dịch trước khi tạo bút toán.');
        }

        $lines = $this->buildJeLines($tx);

        return DB::transaction(function () use ($tx, $lines) {
            $bankAccount = $tx->bankAccount;
            $je = $this->accounting->post(
                description:   $this->buildJeDescription($tx),
                date:          Carbon::parse($tx->transaction_date),
                lines:         $lines,
                referenceType: 'bank_transaction',
                referenceId:   $tx->id,
                isAuto:        false,
            );

            $tx->update([
                'match_status'     => BankTransactionMatchStatus::Posted->value,
                'journal_entry_id' => $je->id,
                'status'           => \App\Enums\BankTransactionStatus::Reconciled,
                'reconciled_at'    => now(),
                'reconciled_by'    => auth()->id(),
            ]);

            return $je;
        });
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function buildCandidates(BankTransaction $tx): array
    {
        $candidates = [];
        $normalizedAcct = $tx->counterpart_account
            ? $this->normalizeAccount($tx->counterpart_account)
            : null;

        // 1. Match by exact account number (+50)
        if ($normalizedAcct) {
            $supplier = SupplierBankAccount::where('is_active', true)
                ->where('normalized_account_number', $normalizedAcct)->first();
            if ($supplier) {
                $candidates[] = $this->makeCandidate('supplier', $supplier->supplier_id, 50, 'Khớp số TK NCC');
            }

            $customer = CustomerBankAccount::where('is_active', true)
                ->where('normalized_account_number', $normalizedAcct)->first();
            if ($customer) {
                $candidates[] = $this->makeCandidate('customer', $customer->customer_id, 50, 'Khớp số TK KH');
            }
        }

        // 2. Match by party name (+20) – only if no account match
        if (empty($candidates) && $tx->counterpart_name) {
            $normalized = $this->normalizeName($tx->counterpart_name);
            $supplier = Supplier::whereRaw("unaccent(lower(name)) ILIKE unaccent(?)", ["%{$normalized}%"])->first();
            if ($supplier) {
                $candidates[] = $this->makeCandidate('supplier', $supplier->id, 20, 'Tên gần giống NCC');
            }
            $customer = Customer::whereRaw("unaccent(lower(name)) ILIKE unaccent(?)", ["%{$normalized}%"])->first();
            if ($customer) {
                $candidates[] = $this->makeCandidate('customer', $customer->id, 20, 'Tên gần giống KH');
            }
        }

        // 3. Boost by description code match (+40)
        foreach ($candidates as &$c) {
            $docMatch = $this->matchDescriptionCode($tx, $c['party_type'], $c['party_id']);
            if ($docMatch) {
                $c['score']         += 40;
                $c['document_type']  = $docMatch['type'];
                $c['document_id']    = $docMatch['id'];
                $c['note']           = ($c['note'] ?? '') . ' + khớp mã chứng từ';
            }
        }
        unset($c);

        // 4. Boost by amount match against open AR/AP (+30)
        foreach ($candidates as &$c) {
            if ($c['party_type'] === 'supplier' && $tx->debit > 0) {
                $inv = PurchaseInvoice::where('supplier_id', $c['party_id'])
                    ->whereIn('status', ['valid', 'partial_paid'])
                    ->whereRaw('ABS(total - ?) < 1000', [$tx->debit])
                    ->first();
                if ($inv) {
                    $c['score']        += 30;
                    $c['document_type'] = 'purchase_invoice';
                    $c['document_id']   = $inv->id;
                }
            }
            if ($c['party_type'] === 'customer' && $tx->credit > 0) {
                $inv = Invoice::where('customer_id', $c['party_id'])
                    ->whereIn('status', ['sent', 'overdue'])
                    ->whereRaw('ABS(total - ?) < 1000', [$tx->credit])
                    ->first();
                if ($inv) {
                    $c['score']        += 30;
                    $c['document_type'] = 'invoice';
                    $c['document_id']   = $inv->id;
                }
            }
        }
        unset($c);

        return $candidates;
    }

    private function makeCandidate(string $partyType, int $partyId, int $score, string $note): array
    {
        return ['party_type' => $partyType, 'party_id' => $partyId, 'score' => $score, 'note' => $note];
    }

    private function matchDescriptionCode(BankTransaction $tx, string $partyType, int $partyId): ?array
    {
        $desc = strtoupper($tx->description ?? '');
        if (preg_match('/H[ĐD]-\d{4}/', $desc, $m)) {
            $inv = Invoice::where('code', $m[0])->first();
            if ($inv) return ['type' => 'invoice', 'id' => $inv->id];
        }
        if (preg_match('/MH-\d{4}/', $desc, $m)) {
            $inv = PurchaseInvoice::where('code', $m[0])->first();
            if ($inv) return ['type' => 'purchase_invoice', 'id' => $inv->id];
        }
        return null;
    }

    private function suggestTxType(BankTransaction $tx, string $partyType, ?string $documentType): string
    {
        if ($partyType === 'customer') {
            return $tx->credit > 0
                ? ($documentType === 'invoice' ? 'customer_receipt' : 'customer_advance_receipt')
                : 'customer_refund';
        }
        if ($partyType === 'supplier') {
            return $tx->debit > 0
                ? ($documentType === 'purchase_invoice' ? 'supplier_payment' : 'supplier_advance_payment')
                : 'supplier_refund';
        }
        return 'other';
    }

    private function buildJeLines(BankTransaction $tx): array
    {
        $bankTk   = $tx->bankAccount->account_code;
        $amount   = max((float)$tx->debit, (float)$tx->credit);
        $txType   = $tx->suggested_tx_type ?? 'other';
        $partyType = $tx->matched_party_type;
        $partyId   = $tx->matched_party_id;

        $counterTk = match($txType) {
            'customer_receipt'         => Customer::findOrFail($partyId)->getReceivableAccount(),
            'customer_advance_receipt' => '131UT',
            'supplier_payment'         => Supplier::findOrFail($partyId)->getPayableAccount(),
            'supplier_advance_payment' => '331UT',
            'supplier_refund'          => '331UT',
            'customer_refund'          => Customer::findOrFail($partyId)->getReceivableAccount(),
            default                    => \App\Services\AccountingSettings::get('bank_fee_account', '6422'),
        };

        $isCredit = $tx->credit > 0;

        return [
            [
                'account'     => $isCredit ? $bankTk : $counterTk,
                'debit'       => (int) $amount,
                'credit'      => 0,
                'description' => $tx->description,
                'partner_type'=> $partyType,
                'partner_id'  => $partyId,
            ],
            [
                'account'     => $isCredit ? $counterTk : $bankTk,
                'debit'       => 0,
                'credit'      => (int) $amount,
                'description' => $tx->description,
                'partner_type'=> $partyType,
                'partner_id'  => $partyId,
            ],
        ];
    }

    private function buildJeDescription(BankTransaction $tx): string
    {
        $party = $tx->matchedPartyName() ?? $tx->counterpart_name ?? '?';
        $dir   = $tx->credit > 0 ? 'Thu' : 'Chi';
        return "{$dir} TK ngân hàng — {$party}: {$tx->description}";
    }

    private function normalizeAccount(string $acct): string
    {
        return preg_replace('/[\s\-\.]/', '', $acct);
    }

    private function normalizeName(string $name): string
    {
        return strtolower(trim($name));
    }
}
