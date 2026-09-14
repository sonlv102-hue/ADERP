<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
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
