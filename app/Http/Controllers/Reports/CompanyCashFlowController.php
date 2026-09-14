<?php

namespace App\Http\Controllers\Reports;

use App\Exports\CompanyCashFlow\CompanyCashFlowExport;
use App\Exports\CompanyCashFlow\ExportFilterMetaBuilder;
use App\Helpers\PartyTypeLabels;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Services\CashFlowClassificationService;
use App\Services\CashFlowInternalTransferMatchingService;
use App\Services\CompanyCashFlowReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CompanyCashFlowController extends Controller
{
    public function __construct(private readonly CompanyCashFlowReportService $reportService)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('reports.bank_cashflow.view');

        $filters = $this->filters($request);

        return Inertia::render('Reports/CompanyCashFlow/Index', [
            'filters' => $filters,
            'summary' => $this->reportService->summary($filters),
            'transactions' => $this->transactionDto($this->reportService->transactions($filters)),
            'byCategory' => $this->reportService->byCategory($filters)->map(fn ($r) => [
                'category_id' => $r->cash_flow_category_id,
                'category_name' => $r->cashFlowCategory?->name ?? 'Chưa xác định',
                'direction' => $r->cashFlowCategory?->direction?->value,
                'total_in' => (float) $r->total_in,
                'total_out' => (float) $r->total_out,
                'tx_count' => (int) $r->tx_count,
            ]),
            'bankAccounts' => BankAccount::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'bank_name', 'account_number']),
            'categories' => CashFlowCategory::query()->active()->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'direction']),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $this->authorize('reports.bank_cashflow.transactions.view');

        $filters = $this->filters($request);

        return response()->json($this->transactionDto($this->reportService->transactions($filters)));
    }

    public function classify(Request $request, BankTransaction $bankTransaction, CashFlowClassificationService $service): RedirectResponse
    {
        $this->authorize('reports.bank_cashflow.reconcile');

        $data = $request->validate([
            'cash_flow_category_id' => 'nullable|exists:cash_flow_categories,id',
            'project_id' => 'nullable|exists:projects,id',
            'contract_type' => 'nullable|in:contract,purchase_contract',
            'contract_id' => 'nullable|integer|min:1',
            'party_type' => 'nullable|in:customer,supplier,employee,shareholder,bank,other_individual,other_entity',
            'party_id' => 'nullable|integer|min:1',
            'party_name' => 'nullable|string|max:255',
            'responsible_user_id' => 'nullable|exists:users,id',
            'cash_flow_note' => 'nullable|string|max:2000',
            'expected_updated_at' => 'nullable|date',
        ]);

        try {
            $service->update($bankTransaction, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', 'Đã cập nhật phân loại giao dịch.');
    }

    public function pairSuggestions(Request $request, CashFlowInternalTransferMatchingService $service): JsonResponse
    {
        $this->authorize('reports.bank_cashflow.reconcile');

        $pairs = $service->suggestPairs();

        return response()->json([
            'data' => collect($pairs)->map(fn ($p) => [
                'outgoing' => $this->txSummary($p['outgoing']),
                'candidates' => collect($p['candidates'])->map(fn ($c) => $this->txSummary($c))->all(),
                'ambiguous' => $p['ambiguous'],
                'confidence' => $p['confidence'],
            ]),
        ]);
    }

    public function confirmPair(Request $request, CashFlowInternalTransferMatchingService $service): RedirectResponse
    {
        $this->authorize('reports.bank_cashflow.reconcile');

        $data = $request->validate([
            'outgoing_id' => 'required|exists:bank_transactions,id',
            'incoming_id' => 'required|exists:bank_transactions,id',
        ]);

        try {
            $service->confirmPair(
                BankTransaction::findOrFail($data['outgoing_id']),
                BankTransaction::findOrFail($data['incoming_id'])
            );
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Đã xác nhận cặp chuyển khoản nội bộ.']);
        }

        return back()->with('success', 'Đã xác nhận cặp chuyển khoản nội bộ.');
    }

    public function unpair(Request $request, BankTransaction $bankTransaction, CashFlowInternalTransferMatchingService $service): RedirectResponse
    {
        $this->authorize('reports.bank_cashflow.reconcile');

        $service->unpair($bankTransaction);

        return back()->with('success', 'Đã hủy cặp chuyển khoản nội bộ.');
    }

    /**
     * Xuất Excel theo ĐÚNG filter hiện tại (Phase 1.1, spec §5): toàn bộ số liệu lấy từ
     * CompanyCashFlowReportService — cùng baseQuery/filters/category/party/account/date/
     * reconcile-status logic với web report, không tính lại. Sheet 02_Giao_dich xuất ĐẦY ĐỦ
     * (kể cả giao dịch chuyển khoản nội bộ, có cột riêng đánh dấu đối ứng — giống sổ giao
     * dịch ngân hàng thật); sheet 01/03/04 áp dụng đúng internal-transfer-exclusion của
     * summary() (spec §6) qua categoryBreakdownForExport() để KPI khớp category breakdown.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('reports.bank_cashflow.export');

        $filters = $this->filters($request);

        $summary = $this->reportService->summary($filters);
        $transactions = $this->reportService->transactionsForExport($filters);
        $contractLabels = $this->reportService->contractLabelsByTransactionId($transactions);
        $byCategory = $this->reportService->categoryBreakdownForExport($transactions, $filters);
        $parties = $this->reportService->partiesBreakdown($transactions);
        $meta = ExportFilterMetaBuilder::build($filters, $request->user());

        $filename = 'Bao_cao_dong_tien_'
            . str_replace('-', '', $summary['from'])
            . '_'
            . str_replace('-', '', $summary['to'])
            . '.xlsx';

        return Excel::download(
            new CompanyCashFlowExport($summary, $transactions, $contractLabels, $byCategory, $parties, $meta),
            $filename
        );
    }

    private function filters(Request $request): array
    {
        $filters = $request->only([
            'from', 'to', 'bank_account_id', 'cash_flow_category_id',
            'party_type', 'party_id', 'project_id', 'direction', 'search', 'reconcile_status',
        ]);

        // BUG THẬT #2 phát hiện qua pre-deploy audit E2E (browser thật, không lộ qua
        // PHPUnit): middleware ConvertEmptyStringsToNull của Laravel biến field CHƯA CHỌN
        // (select mặc định value="") thành `null` TRƯỚC KHI tới đây — nên field "chưa chọn"
        // và field "chọn tường minh -- Chưa xác định --" (Index.vue gửi string "null") ĐỀU
        // arrive dạng có thể trùng nhau nếu không strip null ở đây. Guard cũ chỉ lọc `''`
        // (đã hết tác dụng vì input không còn là '' nữa sau middleware) khiến field
        // cash_flow_category_id CHƯA CHỌN vẫn lọt vào $filters dạng null -> baseQuery() hiểu
        // nhầm thành "lọc giao dịch CHƯA phân loại" -> MỌI giao dịch đã phân loại biến mất
        // khỏi báo cáo mỗi khi bấm "Lọc" mà không chọn category cụ thể. Phải strip CẢ `null`
        // thật lẫn `''` ở đây; sentinel "-- Chưa xác định --" dùng string "null" (xem
        // Index.vue applyFilters() và baseQuery()) để không bị strip nhầm.
        return array_filter($filters, fn ($v) => $v !== '' && $v !== null);
    }

    private function transactionDto($paginator)
    {
        // Batch preload contract label — extracted sang CompanyCashFlowReportService để
        // Export Excel (Phase 1.1) dùng lại đúng 1 chỗ, không copy lại logic N+1-fix.
        $items = $paginator->getCollection();
        $contractLabels = $this->reportService->contractLabelsByTransactionId($items);

        return $paginator->through(fn (BankTransaction $t) => [
            'id' => $t->id,
            'transaction_date' => $t->transaction_date?->format('Y-m-d'),
            'bank_account_name' => $t->bankAccount?->name,
            'description' => $t->description,
            'debit' => (float) $t->debit,
            'credit' => (float) $t->credit,
            'counterpart_name' => $t->counterpart_name,
            'counterpart_account' => $t->counterpart_account,
            'category_name' => $t->cashFlowCategory?->name,
            'category_id' => $t->cash_flow_category_id,
            'party_type' => $t->party_type,
            'party_id' => $t->party_id,
            'party_name' => $t->party_name,
            'party_type_label' => PartyTypeLabels::label($t->party_type),
            'project_id' => $t->project_id,
            'project_name' => $t->project?->name,
            'contract_type' => $t->contract_type,
            'contract_id' => $t->contract_id,
            'contract_label' => $contractLabels[$t->id] ?? null,
            'responsible_user_id' => $t->responsible_user_id,
            'responsible_user_name' => $t->responsibleUser?->name,
            'cash_flow_note' => $t->cash_flow_note,
            'is_paired' => $t->paired_transaction_id !== null,
            'paired_with' => $t->pairedTransaction ? [
                'id' => $t->pairedTransaction->id,
                'bank_account_name' => $t->pairedTransaction->bankAccount?->name,
                'transaction_date' => $t->pairedTransaction->transaction_date?->format('Y-m-d'),
                'amount' => (float) ($t->pairedTransaction->debit ?: $t->pairedTransaction->credit),
                'description' => $t->pairedTransaction->description,
            ] : null,
            'updated_at' => $t->updated_at?->toJSON(),
            'reconcile_status' => $t->reconcileStatus()->value,
            'reconcile_status_label' => $t->reconcileStatus()->label(),
            'reconcile_status_color' => $t->reconcileStatus()->color(),
        ]);
    }

    private function txSummary(BankTransaction $t): array
    {
        return [
            'id' => $t->id,
            'bank_account_name' => $t->bankAccount?->name,
            'transaction_date' => $t->transaction_date?->format('Y-m-d'),
            'amount' => (float) ($t->debit ?: $t->credit),
            'description' => $t->description,
        ];
    }
}
