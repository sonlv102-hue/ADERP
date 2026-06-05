<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\BankTransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\JournalEntry;
use App\Services\BankReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BankTransactionController extends Controller
{
    public function __construct(private BankReconciliationService $service) {}

    public function index(Request $request, BankAccount $bankAccount): Response
    {
        $status      = $request->input('status');
        $txType      = $request->input('tx_type');
        $from        = $request->input('date_from');
        $to          = $request->input('date_to');
        $counterpart = $request->input('counterpart');

        $query = $bankAccount->transactions()
            ->with('journalEntry:id,code')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id');

        if ($status)      $query->where('status', $status);
        if ($txType)      $query->where('tx_type', $txType);
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
        ]);

        // Suggest unposted JEs on TK 112 for reconciliation
        $pendingJEs = JournalEntry::where('status', 'posted')
            ->whereHas('lines', fn ($q) => $q->where('account_code', 'like', '112%'))
            ->orderByDesc('entry_date')
            ->limit(50)
            ->get(['id', 'code', 'entry_date', 'description']);

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
            'pendingJEs'   => $pendingJEs,
            'alertCount'   => $alertCount,
            'filters'      => $request->only(['counterpart', 'tx_type', 'status', 'date_from', 'date_to']),
            'statuses'     => collect(BankTransactionStatus::cases())->map(fn ($s) => [
                'value' => $s->value, 'label' => $s->label(),
            ]),
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

    public function importExcel(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = $this->service->importExcel($bankAccount, $request->file('excel_file'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
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
}
