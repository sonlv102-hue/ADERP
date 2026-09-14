<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Contract;
use App\Models\PurchaseContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Đọc-only. Toàn bộ method nhận cùng 1 bộ filter (spec §11/§26) để summary,
 * bảng giao dịch và các bảng tổng hợp luôn khớp số với nhau (Test 5).
 *
 * Filter keys: from, to, bank_account_id, cash_flow_category_id, party_type,
 * party_id, project_id, direction (in|out), search.
 */
class CompanyCashFlowReportService
{
    public function summary(array $filters): array
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        $accounts = $this->accountsInScope($filters);

        $opening = 0.0;
        $closing = 0.0;
        foreach ($accounts as $account) {
            $netBefore = (float) $account->transactions()
                ->whereDate('transaction_date', '<', $from)
                ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')->value('net');
            $netUpToEnd = (float) $account->transactions()
                ->whereDate('transaction_date', '<=', $to)
                ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')->value('net');
            $opening += (float) $account->opening_balance + $netBefore;
            $closing += (float) $account->opening_balance + $netUpToEnd;
        }

        $periodQuery = $this->baseQuery($filters)->forPeriod($from, $to);

        // Loại trừ chuyển khoản nội bộ khỏi tiền vào/ra CHỈ khi xem TOÀN CÔNG TY
        // (không lọc theo 1 tài khoản cụ thể) — spec §5: xem riêng từng account vẫn
        // phải thấy đúng số tiền thực đã ra/vào account đó. Loại theo cả pairing lẫn
        // classification (scopeExcludingInternalTransfers, spec §6) — không chỉ dựa
        // vào đã tìm được cặp hay chưa.
        $isConsolidated = empty($filters['bank_account_id']);
        $inflowQuery = (clone $periodQuery);
        $outflowQuery = (clone $periodQuery);
        if ($isConsolidated) {
            $inflowQuery->excludingInternalTransfers();
            $outflowQuery->excludingInternalTransfers();
        }
        $inflow = (float) $inflowQuery->sum('credit');
        $outflow = (float) $outflowQuery->sum('debit');

        $unclassifiedIn = (float) (clone $periodQuery)->whereNull('cash_flow_category_id')->sum('credit');
        $unclassifiedOut = (float) (clone $periodQuery)->whereNull('cash_flow_category_id')->sum('debit');

        return [
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'inflow' => $inflow,
            'outflow' => $outflow,
            'net_cash_flow' => $inflow - $outflow,
            'closing_balance' => $closing,
            'unclassified_inflow' => $unclassifiedIn,
            'unclassified_outflow' => $unclassifiedOut,
            // spec §7: chưa có nguồn "số dư xác nhận từ sao kê ngân hàng" trong hệ
            // thống — số dư này LUÔN được TÍNH TOÁN từ bank_transactions, không phải
            // số dư đã đối chiếu với ngân hàng. FE phải hiển thị rõ (tooltip/label).
            'balance_source' => 'calculated',
        ];
    }

    public function transactions(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return $this->baseQuery($filters)
            ->forPeriod($from, $to)
            ->with(['bankAccount', 'cashFlowCategory', 'project', 'responsibleUser', 'pairedTransaction.bankAccount'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Bản không phân trang của transactions() — dùng cho Export Excel (Phase 1.1, spec §5/§20:
     * Excel không được phụ thuộc pagination). Cùng baseQuery()/forPeriod()/eager-load với
     * transactions() — không phải bộ SQL riêng.
     */
    public function transactionsForExport(array $filters): Collection
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return $this->baseQuery($filters)
            ->forPeriod($from, $to)
            ->with(['bankAccount', 'cashFlowCategory', 'project', 'responsibleUser', 'pairedTransaction.bankAccount'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Batch preload contract label theo N+1-safe pattern (extracted từ
     * CompanyCashFlowController::transactionDto() — pre-deploy audit fix — để Export Excel
     * dùng lại đúng 1 chỗ, không copy lại logic). Trả về mảng [transaction_id => label|null].
     */
    public function contractLabelsByTransactionId(Collection $transactions): array
    {
        $contractIds = $transactions->where('contract_type', 'contract')->pluck('contract_id')->filter()->unique();
        $purchaseContractIds = $transactions->where('contract_type', 'purchase_contract')->pluck('contract_id')->filter()->unique();

        $contracts = $contractIds->isNotEmpty()
            ? Contract::query()->whereIn('id', $contractIds)->get(['id', 'code', 'title'])->keyBy('id')
            : collect();
        $purchaseContracts = $purchaseContractIds->isNotEmpty()
            ? PurchaseContract::query()->whereIn('id', $purchaseContractIds)->get(['id', 'code', 'title'])->keyBy('id')
            : collect();

        return $transactions->mapWithKeys(function (BankTransaction $t) use ($contracts, $purchaseContracts) {
            $contract = match ($t->contract_type) {
                'contract' => $contracts->get($t->contract_id),
                'purchase_contract' => $purchaseContracts->get($t->contract_id),
                default => null,
            };

            return [$t->id => $contract ? "{$contract->code} — {$contract->title}" : null];
        })->all();
    }

    /**
     * Tổng hợp theo (party_type + party_id) từ CHÍNH tập giao dịch đã lấy qua
     * transactionsForExport() (spec §14, sheet 05_Doi_tuong) — không query SQL riêng, không
     * group theo party_name (tránh trùng tên khác loại/khác id), tách rõ số giao dịch
     * tiền vào/tiền ra theo cùng định nghĩa "credit>0 = vào, debit>0 = ra" dùng ở sheet 02.
     */
    public function partiesBreakdown(Collection $transactions): array
    {
        $identified = $transactions->filter(fn (BankTransaction $t) => $t->party_type !== null);
        $unidentifiedGroup = $transactions->filter(fn (BankTransaction $t) => $t->party_type === null);

        $rows = collect();
        foreach ($identified->groupBy(fn (BankTransaction $t) => $t->party_type . '|' . $t->party_id) as $group) {
            $first = $group->first();
            $rows->push([
                'party_type' => $first->party_type,
                'party_id' => $first->party_id,
                'party_name' => $first->party_name,
                'total_in' => (float) $group->sum('credit'),
                'total_out' => (float) $group->sum('debit'),
                'in_count' => $group->filter(fn (BankTransaction $t) => (float) $t->credit > 0)->count(),
                'out_count' => $group->filter(fn (BankTransaction $t) => (float) $t->debit > 0)->count(),
            ]);
        }

        return [
            'rows' => $rows,
            'unidentified' => [
                'total_in' => (float) $unidentifiedGroup->sum('credit'),
                'total_out' => (float) $unidentifiedGroup->sum('debit'),
                'in_count' => $unidentifiedGroup->filter(fn (BankTransaction $t) => (float) $t->credit > 0)->count(),
                'out_count' => $unidentifiedGroup->filter(fn (BankTransaction $t) => (float) $t->debit > 0)->count(),
            ],
        ];
    }

    /**
     * Tổng hợp theo category từ CHÍNH tập giao dịch của transactionsForExport() — dùng cho
     * sheet 03_Tien_vao/04_Tien_ra (spec §12/§13: "Tổng tiền phải bằng KPI"). KHÔNG dùng
     * byCategory() thẳng cho export vì byCategory() không loại internal transfer, trong khi
     * summary() (nguồn KPI sheet 01) CÓ loại khi xem consolidated (spec §6) — nếu export
     * dùng byCategory() nguyên bản, sheet 03/04 có thể lệch KPI đúng lúc có internal transfer
     * chưa cặp/không cặp được. Áp dụng đúng 1 điều kiện loại trừ của
     * scopeExcludingInternalTransfers() (paired HOẶC category nội bộ) trên tập transaction đã
     * có sẵn — không phải bộ SQL/business rule mới, chỉ chuyển từ SQL sang lọc trên collection
     * đã lấy theo baseQuery() để tránh query thêm.
     */
    public function categoryBreakdownForExport(Collection $transactions, array $filters): Collection
    {
        $isConsolidated = empty($filters['bank_account_id']);

        $relevant = $isConsolidated
            ? $transactions->filter(fn (BankTransaction $t) => $t->paired_transaction_id === null
                && !($t->cashFlowCategory?->isInternalTransfer() ?? false))
            : $transactions;

        return $relevant->groupBy(fn (BankTransaction $t) => $t->cash_flow_category_id ?? 'null')
            ->map(function (Collection $group) {
                $first = $group->first();

                return (object) [
                    'cash_flow_category_id' => $first->cash_flow_category_id,
                    'cashFlowCategory' => $first->cashFlowCategory,
                    'total_in' => (float) $group->sum('credit'),
                    'total_out' => (float) $group->sum('debit'),
                    'tx_count' => $group->count(),
                ];
            })
            ->values();
    }

    public function byCategory(array $filters): Collection
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return $this->baseQuery($filters)->forPeriod($from, $to)
            ->selectRaw('cash_flow_category_id, SUM(credit) as total_in, SUM(debit) as total_out, COUNT(*) as tx_count')
            ->groupBy('cash_flow_category_id')
            ->with('cashFlowCategory')
            ->get();
    }

    public function byParty(array $filters, string $partyType): Collection
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return $this->baseQuery($filters)->forPeriod($from, $to)
            ->where('party_type', $partyType)
            ->selectRaw('party_id, party_name, SUM(credit) as total_in, SUM(debit) as total_out, COUNT(*) as tx_count')
            ->groupBy('party_id', 'party_name')
            ->get();
    }

    public function byProject(array $filters, ?int $projectId = null): Collection
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return $this->baseQuery($filters)->forPeriod($from, $to)
            ->when($projectId, fn (Builder $q) => $q->where('project_id', $projectId))
            ->whereNotNull('project_id')
            ->selectRaw('project_id, SUM(credit) as total_in, SUM(debit) as total_out, COUNT(*) as tx_count')
            ->groupBy('project_id')
            ->with('project')
            ->get();
    }

    private function baseQuery(array $filters): Builder
    {
        return BankTransaction::query()
            ->when($filters['bank_account_id'] ?? null, fn (Builder $q, $v) => $q->where('bank_account_id', $v))
            ->when(array_key_exists('cash_flow_category_id', $filters), function (Builder $q) use ($filters) {
                // Sentinel là STRING "null" (không phải PHP null) — xem
                // CompanyCashFlowController::filters(): PHP null nghĩa là "chưa chọn",
                // đã bị strip trước khi tới đây; chỉ string "null" mới là lọc tường minh
                // "-- Chưa xác định --" (Index.vue applyFilters() gửi nguyên string này).
                $filters['cash_flow_category_id'] === 'null'
                    ? $q->whereNull('cash_flow_category_id')
                    : $q->where('cash_flow_category_id', $filters['cash_flow_category_id']);
            })
            ->when($filters['party_type'] ?? null, fn (Builder $q, $v) => $q->where('party_type', $v))
            ->when($filters['party_id'] ?? null, fn (Builder $q, $v) => $q->where('party_id', $v))
            ->when($filters['project_id'] ?? null, fn (Builder $q, $v) => $q->where('project_id', $v))
            ->when(($filters['direction'] ?? null) === 'in', fn (Builder $q) => $q->where('credit', '>', 0))
            ->when(($filters['direction'] ?? null) === 'out', fn (Builder $q) => $q->where('debit', '>', 0))
            ->when($filters['reconcile_status'] ?? null, fn (Builder $q, $status) => $this->applyReconcileStatusFilter($q, $status))
            ->when($filters['search'] ?? null, function (Builder $q, $term) {
                $needle = mb_strtolower(trim($term), 'UTF-8');
                $q->where(fn (Builder $b) => $b
                    ->whereRaw('LOWER(description) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(counterpart_name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(counterpart_account) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(reference) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(party_name) LIKE ?', ["%{$needle}%"])
                );
            });
    }

    /**
     * Phải khớp CHÍNH XÁC logic BankTransaction::reconcileStatus() (xem doc-block ở đó —
     * đây là nguồn chuẩn business rule). Test cross-check: CompanyCashFlowFilterAndAuthTest.
     */
    private function applyReconcileStatusFilter(Builder $query, string $status): Builder
    {
        $hasDocument = fn (Builder $b) => $b->whereNotNull('cash_voucher_id')
            ->orWhereNotNull('matched_document_id')
            ->orWhereNotNull('contract_id');

        $internalCategoryIds = CashFlowCategory::query()
            ->whereIn('code', CashFlowCategory::INTERNAL_TRANSFER_CODES)->pluck('id');

        // needs_review = category "chuyển tiền nội bộ" + CHƯA cặp đôi. whereIn/whereNotIn
        // với mảng rỗng tự động thành "0=1"/luôn true trong Laravel — không cần guard rỗng.
        $needsReview = fn (Builder $b) => $b->whereNull('paired_transaction_id')->whereIn('cash_flow_category_id', $internalCategoryIds);
        $notNeedsReview = fn (Builder $b) => $b->whereNotIn('cash_flow_category_id', $internalCategoryIds)->orWhereNotNull('paired_transaction_id');

        return match ($status) {
            'unclassified' => $query->whereNull('party_type')->whereNull('cash_flow_category_id'),
            'needs_review' => $query->where($needsReview),
            'completed' => $query->whereNotNull('party_type')->whereNotNull('cash_flow_category_id')
                ->where($hasDocument)->where($notNeedsReview),
            // document_linked = hasDocument VÀ đúng 1 trong 2 (party_type, category) đã set,
            // trừ nhánh category-only phải loại needs_review (ưu tiên cao hơn — xem model).
            'document_linked' => $query->where(function (Builder $b) use ($hasDocument, $notNeedsReview) {
                $b->where(fn (Builder $x) => $x->where($hasDocument)->whereNotNull('party_type')->whereNull('cash_flow_category_id'))
                    ->orWhere(fn (Builder $x) => $x->where($hasDocument)->whereNull('party_type')->whereNotNull('cash_flow_category_id')->where($notNeedsReview));
            }),
            'categorized' => $query->whereNotNull('cash_flow_category_id')
                ->whereNull('cash_voucher_id')->whereNull('matched_document_id')->whereNull('contract_id')
                ->where($notNeedsReview),
            'party_identified' => $query->whereNotNull('party_type')->whereNull('cash_flow_category_id')
                ->whereNull('cash_voucher_id')->whereNull('matched_document_id')->whereNull('contract_id'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function accountsInScope(array $filters): Collection
    {
        return BankAccount::query()
            ->when($filters['bank_account_id'] ?? null, fn (Builder $q, $v) => $q->where('id', $v))
            ->get();
    }
}
