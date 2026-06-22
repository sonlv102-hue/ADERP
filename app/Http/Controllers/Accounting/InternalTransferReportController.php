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

        $periodType = $request->input('period_type', 'month');
        $month      = $request->input('month', now()->format('Y-m'));
        $year       = $request->input('year', now()->format('Y'));
        $fromDate   = $request->input('from_date');
        $toDate     = $request->input('to_date');

        // Multi-select: internal_account_ids[] (new) hoặc internal_account_id (backward compat)
        $internalAccountIds = $request->input('internal_account_ids', []);
        if (empty($internalAccountIds) && $request->filled('internal_account_id')) {
            $internalAccountIds = [$request->input('internal_account_id')];
        }
        $internalAccountIds = collect($internalAccountIds)
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if ($request->has('internal_account_ids') && !empty($internalAccountIds)) {
            $request->validate([
                'internal_account_ids'   => ['array'],
                'internal_account_ids.*' => ['integer', 'exists:internal_bank_accounts,id'],
            ]);
        }

        // Compute date range based on period_type
        $from = null;
        $to   = null;
        switch ($periodType) {
            case 'year':
                $from = Carbon::create((int) $year, 1, 1)->startOfDay();
                $to   = Carbon::create((int) $year, 12, 31)->endOfDay();
                break;
            case 'custom':
                $request->validate([
                    'from_date' => ['required', 'date'],
                    'to_date'   => ['required', 'date', 'after_or_equal:from_date'],
                ], [
                    'to_date.after_or_equal' => 'Đến ngày phải lớn hơn hoặc bằng Từ ngày.',
                ]);
                $from = Carbon::parse($fromDate)->startOfDay();
                $to   = Carbon::parse($toDate)->endOfDay();
                break;
            case 'all':
                break;
            default: // month
                [$y, $mon] = explode('-', $month);
                $from = Carbon::create((int) $y, (int) $mon, 1)->startOfMonth();
                $to   = $from->copy()->endOfMonth();
                break;
        }

        $query = BankTransaction::where('tx_type', 'internal_transfer')
            ->with('bankAccount:id,name,bank_name,account_number', 'internalAccount:id,name,account_number,bank_name')
            ->orderBy('transaction_date')
            ->orderBy('id');

        if ($from && $to) {
            $query->whereBetween('transaction_date', [$from, $to]);
        }

        if (!empty($internalAccountIds)) {
            $query->whereIn('internal_account_id', $internalAccountIds);
        }

        $txs = $query->get();

        $allInternalAccounts = InternalBankAccount::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'account_number', 'bank_name'])
            ->map(fn ($a) => [
                'id'             => $a->id,
                'name'           => $a->name,
                'account_number' => $a->account_number,
                'bank_name'      => $a->bank_name,
            ]);

        $summary = [
            'total_debit'   => (int) $txs->sum('debit'),
            'total_credit'  => (int) $txs->sum('credit'),
            'net'           => (int) ($txs->sum('credit') - $txs->sum('debit')),
            'count'         => $txs->count(),
            'pending_count' => $txs->whereIn('internal_status', [null, 'pending'])->count(),
            'needs_return'  => (int) $txs->where('internal_status', 'needs_return')->sum('return_amount'),
        ];

        $monthExpr = \DB::getDriverName() === 'pgsql'
            ? "to_char(transaction_date, 'YYYY-MM')"
            : "strftime('%Y-%m', transaction_date)";

        $availableMonths = BankTransaction::where('tx_type', 'internal_transfer')
            ->selectRaw("{$monthExpr} as month")
            ->groupByRaw($monthExpr)
            ->orderByRaw("{$monthExpr} desc")
            ->pluck('month');

        $periodLabel = match ($periodType) {
            'year'   => "Năm {$year}",
            'custom' => $fromDate && $toDate
                ? 'Từ ' . Carbon::parse($fromDate)->format('d/m/Y') . ' đến ' . Carbon::parse($toDate)->format('d/m/Y')
                : 'Khoảng thời gian',
            'all'    => 'Tất cả',
            default  => (function () use ($month) {
                [$y, $mon] = explode('-', $month);
                return 'Tháng ' . (int) $mon . '/' . $y;
            })(),
        };

        return Inertia::render('Accounting/InternalTransferReport/Index', [
            'periodType'          => $periodType,
            'month'               => $month,
            'year'                => $year,
            'fromDate'            => $fromDate,
            'toDate'              => $toDate,
            'periodLabel'         => $periodLabel,
            'availableMonths'     => $availableMonths,
            'internalAccountIds'  => $internalAccountIds,
            'allInternalAccounts' => $allInternalAccounts,
            'summary'             => $summary,
            'transactions'        => $txs->map(fn ($t) => [
                'id'                    => $t->id,
                'transaction_date'      => $t->transaction_date->format('d/m/Y'),
                'description'           => $t->description,
                'reference'             => $t->reference,
                'counterpart_account'   => $t->counterpart_account,
                'counterpart_name'      => $t->counterpart_name,
                'counterpart_bank'      => $t->counterpart_bank,
                'debit'                 => (float) $t->debit,
                'credit'                => (float) $t->credit,
                'bank_account_name'     => $t->bankAccount?->name,
                'internal_account'      => $t->internalAccount?->name,
                'internal_status'       => $t->internal_status ?? 'pending',
                'internal_status_label' => $t->internalStatusLabel(),
                'internal_status_color' => $t->internalStatusColor(),
                'internal_note'         => $t->internal_note,
                'return_amount'         => (float) ($t->return_amount ?? 0),
                'alert_note'            => $t->alert_note,
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
