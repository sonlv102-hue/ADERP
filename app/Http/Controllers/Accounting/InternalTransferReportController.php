<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\InternalBankAccount;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InternalTransferReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        $month             = $request->input('month', now()->format('Y-m'));
        $internalAccountId = $request->input('internal_account_id');
        [$year, $mon] = explode('-', $month);

        $from = Carbon::create($year, $mon, 1)->startOfMonth();
        $to   = $from->copy()->endOfMonth();

        $query = BankTransaction::where('tx_type', 'internal_transfer')
            ->whereBetween('transaction_date', [$from, $to])
            ->with('bankAccount:id,name,bank_name,account_number', 'internalAccount:id,name,account_number,bank_name')
            ->orderBy('transaction_date')
            ->orderBy('id');

        if ($internalAccountId) {
            $query->where('internal_account_id', $internalAccountId);
        }

        $txs = $query->get();

        // Danh sách TK nội bộ xuất hiện trong tháng này (để lọc)
        $accountsInMonth = BankTransaction::where('tx_type', 'internal_transfer')
            ->whereBetween('transaction_date', [$from, $to])
            ->whereNotNull('internal_account_id')
            ->with('internalAccount:id,name,account_number,bank_name')
            ->select('internal_account_id')
            ->distinct()
            ->get()
            ->map(fn ($t) => $t->internalAccount)
            ->filter()
            ->values();

        $summary = [
            'total_debit'       => (int) $txs->sum('debit'),
            'total_credit'      => (int) $txs->sum('credit'),
            'net'               => (int) ($txs->sum('credit') - $txs->sum('debit')),
            'count'             => $txs->count(),
            'pending_count'     => $txs->whereIn('internal_status', [null, 'pending'])->count(),
            'needs_return'      => (int) $txs->where('internal_status', 'needs_return')->sum('return_amount'),
        ];

        // Month list for selector (all months that have internal transfers)
        $availableMonths = BankTransaction::where('tx_type', 'internal_transfer')
            ->selectRaw("to_char(transaction_date, 'YYYY-MM') as month")
            ->groupByRaw("to_char(transaction_date, 'YYYY-MM')")
            ->orderByRaw("to_char(transaction_date, 'YYYY-MM') desc")
            ->pluck('month');

        return Inertia::render('Accounting/InternalTransferReport/Index', [
            'month'              => $month,
            'availableMonths'    => $availableMonths,
            'internalAccountId'  => $internalAccountId ? (int)$internalAccountId : null,
            'accountsInMonth'    => $accountsInMonth->map(fn ($a) => [
                'id'             => $a->id,
                'name'           => $a->name,
                'account_number' => $a->account_number,
                'bank_name'      => $a->bank_name,
            ]),
            'summary'            => $summary,
            'transactions'    => $txs->map(fn ($t) => [
                'id'                  => $t->id,
                'transaction_date'    => $t->transaction_date->format('d/m/Y'),
                'description'         => $t->description,
                'reference'           => $t->reference,
                'counterpart_account' => $t->counterpart_account,
                'counterpart_name'    => $t->counterpart_name,
                'counterpart_bank'    => $t->counterpart_bank,
                'debit'               => (float) $t->debit,
                'credit'              => (float) $t->credit,
                'bank_account_name'   => $t->bankAccount?->name,
                'internal_account'    => $t->internalAccount?->name,
                'internal_status'     => $t->internal_status ?? 'pending',
                'internal_status_label' => $t->internalStatusLabel(),
                'internal_status_color' => $t->internalStatusColor(),
                'internal_note'       => $t->internal_note,
                'return_amount'       => (float) ($t->return_amount ?? 0),
                'alert_note'          => $t->alert_note,
            ]),
        ]);
    }

    public function updateStatus(Request $request, BankTransaction $bankTransaction): RedirectResponse
    {
        $this->authorize('accounting.manage');

        abort_if($bankTransaction->tx_type !== 'internal_transfer', 403);

        $data = $request->validate([
            'internal_status' => ['required', 'in:pending,docs_done,needs_return,returned'],
            'internal_note'   => ['nullable', 'string', 'max:500'],
            'return_amount'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $bankTransaction->update($data);

        return back()->with('success', 'Đã cập nhật trạng thái.');
    }
}
