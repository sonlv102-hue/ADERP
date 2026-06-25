<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\BankTransactionMatchStatus;
use App\Enums\BankTransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\BankReconciliationService;
use App\Services\BankTransactionAllocationService;
use App\Services\BankTransactionMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BankTransactionController extends Controller
{
    public function __construct(
        private BankReconciliationService $service,
        private BankTransactionMatchingService $matching,
        private BankTransactionAllocationService $allocationService,
    ) {}

    public function index(Request $request, BankAccount $bankAccount): Response
    {
        $status      = $request->input('status');
        $txType      = $request->input('tx_type');
        $matchStatus = $request->input('match_status');
        $from        = $request->input('date_from');
        $to          = $request->input('date_to');
        $counterpart = $request->input('counterpart');

        $query = $bankAccount->transactions()
            ->with('journalEntry:id,code')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        if ($status)      $query->where('status', $status);
        if ($txType)      $query->where('tx_type', $txType);
        if ($matchStatus) $query->where('match_status', $matchStatus);
        if ($from)        $query->where('transaction_date', '>=', $from);
        if ($to)          $query->where('transaction_date', '<=', $to);
        if ($counterpart) {
            $kw = '%' . $counterpart . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('counterpart_account', 'ilike', $kw)
                  ->orWhere('counterpart_name', 'ilike', $kw);
            });
        }

        $alertCount = $bankAccount->transactions()
            ->where('tx_type', 'internal_transfer')
            ->whereNotNull('alert_note')
            ->count();

        $transactions = $query->paginate(30)->through(fn ($t) => [
            'id'                  => $t->id,
            'transaction_date'    => $t->transaction_date?->toDateString(),
            'description'         => $t->description,
            'reference'           => $t->reference,
            'debit'               => (float)$t->debit,
            'credit'              => (float)$t->credit,
            'counterpart_bank'    => $t->counterpart_bank,
            'counterpart_account' => $t->counterpart_account,
            'counterpart_name'    => $t->counterpart_name,
            'tx_type'             => $t->tx_type,
            'tx_type_label'       => $t->txTypeLabel(),
            'alert_note'          => $t->alert_note,
            'status'              => $t->status->value,
            'status_label'        => $t->status->label(),
            'status_color'        => $t->status->color(),
            'journal_entry_id'    => $t->journal_entry_id,
            'journal_entry_code'  => $t->journalEntry?->code,
            // Matching fields
            'match_status'        => $t->match_status?->value ?? BankTransactionMatchStatus::Unmatched->value,
            'match_status_label'  => $t->match_status?->label() ?? BankTransactionMatchStatus::Unmatched->label(),
            'match_status_color'  => $t->match_status?->color() ?? BankTransactionMatchStatus::Unmatched->color(),
            'matched_party_type'  => $t->matched_party_type,
            'matched_party_id'    => $t->matched_party_id,
            'matched_party_name'  => $t->matchedPartyName(),
            'matched_document_type' => $t->matched_document_type,
            'matched_document_id' => $t->matched_document_id,
            'confidence_score'    => $t->confidence_score,
            'suggested_tx_type'   => $t->suggested_tx_type,
            'match_note'          => $t->match_note,
        ]);

        return Inertia::render('Accounting/BankTransactions/Index', [
            'bankAccount'  => [
                'id'             => $bankAccount->id,
                'name'           => $bankAccount->name,
                'bank_name'      => $bankAccount->bank_name,
                'account_number' => $bankAccount->account_number,
                'account_code'   => $bankAccount->account_code,
                'balance'        => $bankAccount->currentBalance(),
            ],
            'transactions' => $transactions,
            'alertCount'   => $alertCount,
            'filters'      => $request->only(['counterpart', 'tx_type', 'status', 'match_status', 'date_from', 'date_to']),
            'statuses'     => collect(BankTransactionStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'matchStatuses' => collect(BankTransactionMatchStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function store(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $data = $request->validate([
            'transaction_date' => 'required|date',
            'description'      => 'required|string|max:500',
            'reference'        => 'nullable|string|max:100',
            'debit'            => 'nullable|numeric|min:0',
            'credit'           => 'nullable|numeric|min:0',
        ]);

        $this->service->createTransaction($bankAccount, $data);

        return back()->with('success', 'Đã thêm giao dịch ngân hàng.');
    }

    public function reconcile(Request $request, BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        $request->validate(['journal_entry_id' => 'required|exists:journal_entries,id']);

        try {
            $this->service->reconcile($bankTransaction, (int)$request->journal_entry_id);
            return back()->with('success', 'Đã đối chiếu thành công.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }

    public function unreconcile(BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        try {
            $this->service->unreconcile($bankTransaction);
            return back()->with('success', 'Đã hủy đối chiếu.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }

    public function recategorize(BankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $result = $this->service->recategorizeUnknown($bankAccount);

        $msg = $result['total'] === 0
            ? 'Không có giao dịch chưa phân loại nào.'
            : "Đã phân loại lại {$result['updated']}/{$result['total']} giao dịch.";

        return back()->with('success', $msg);
    }

    public function importExcel(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $request->validate([
            'excel_file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $result = $this->service->importExcel($bankAccount, $request->file('excel_file'));
        } catch (\Throwable $e) {
            \Log::error('Import Excel failed', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            return back()->with('error', 'Import thất bại: ' . $e->getMessage());
        }

        $msg = "Import hoàn tất: đã thêm {$result['imported']} giao dịch";
        if ($result['skipped'] > 0) {
            $msg .= ", bỏ qua {$result['skipped']} trùng";
        }
        if (!empty($result['errors'])) {
            $msg .= '. Lỗi: ' . implode('; ', array_slice($result['errors'], 0, 3));
        }
        $msg .= '.';

        return back()->with('success', $msg);
    }

    public function exportExcel(\Illuminate\Http\Request $request, \App\Models\BankAccount $bankAccount): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $filters = array_merge($request->all(), ['bank_account_id' => $bankAccount->id]);
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BankTransactionsExport($filters),
            'giao-dich-ngan-hang_' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    /** POST: Tự động đối soát hàng loạt tất cả giao dịch chưa xử lý. */
    public function matchAll(BankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $result = $this->matching->matchAll($bankAccount);

        return back()->with('success',
            "Đối soát xong {$result['total']} giao dịch. Đề xuất: {$result['suggested']}."
        );
    }

    /** POST: Xác nhận (hoặc chỉnh sửa) đề xuất cho 1 giao dịch. */
    public function confirmMatch(Request $request, BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'matched_party_type'    => 'nullable|in:customer,supplier',
            'matched_party_id'      => 'nullable|integer',
            'matched_document_type' => 'nullable|string',
            'matched_document_id'   => 'nullable|integer',
            'tx_type'               => 'nullable|string',
            'match_note'            => 'nullable|string|max:500',
        ]);

        try {
            $this->matching->confirmMatch($bankTransaction, $data);
            return back()->with('success', 'Đã xác nhận đề xuất.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }

    /** POST: Bỏ qua giao dịch này (không cần đối soát). */
    public function ignoreMatch(BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->authorize('accounting.manage');
        $this->matching->ignoreMatch($bankTransaction);
        return back()->with('success', 'Đã đánh dấu bỏ qua.');
    }

    /** POST: Tạo bút toán kế toán cho giao dịch đã confirmed (auto-match flow). */
    public function createJournalEntry(BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $je = $this->matching->createJournalEntry($bankTransaction);
            return back()->with('success', "Đã tạo bút toán {$je->code}.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }

    /** GET: Trả về dữ liệu đối chiếu (party + chứng từ mở) cho modal. */
    public function reconcileData(Request $request, BankAccount $bankAccount, BankTransaction $bankTransaction): JsonResponse
    {
        $this->authorize('accounting.manage');

        $data = $this->allocationService->getReconcileData(
            $bankTransaction,
            $request->input('party_type'),
            $request->integer('party_id') ?: null,
        );

        return response()->json($data);
    }

    /** POST: Phân bổ và tạo bút toán (manual allocation flow). */
    public function allocate(Request $request, BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'party_type'               => 'required|in:customer,supplier',
            'party_id'                 => 'required|integer',
            'allocations'              => 'required|array|min:1',
            'allocations.*.type'       => 'required|string',
            'allocations.*.id'         => 'nullable|integer',
            'allocations.*.account_code' => 'required|string|max:20',
            'allocations.*.amount'     => 'required|numeric|min:1',
            'allocations.*.description' => 'nullable|string|max:300',
        ]);

        try {
            $je = $this->allocationService->allocate(
                $bankTransaction,
                ['type' => $data['party_type'], 'id' => $data['party_id'],
                 'name' => $this->resolvePartyName($data['party_type'], $data['party_id'])],
                $data['allocations'],
            );
            return back()->with('success', "Đã đối chiếu và tạo bút toán {$je->code}.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }

    /** POST: Hủy đối chiếu — đảo JE + reset status. */
    public function cancelAllocation(Request $request, BankAccount $bankAccount, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $this->allocationService->cancelAllocation(
                $bankTransaction,
                $request->input('reason', 'Người dùng hủy đối chiếu'),
            );
            return back()->with('success', 'Đã hủy đối chiếu và tạo bút toán đảo.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }
    }

    private function resolvePartyName(string $type, int $id): string
    {
        if ($type === 'customer') return \App\Models\Customer::find($id)?->name ?? '';
        if ($type === 'supplier') return \App\Models\Supplier::find($id)?->name ?? '';
        return '';
    }
}
