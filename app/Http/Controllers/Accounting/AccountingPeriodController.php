<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingPeriodController extends Controller
{
    public function index(): Response
    {
        $periods = AccountingPeriod::orderByDesc('year')->orderByDesc('month')
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id,
                'year'         => $p->year,
                'month'        => $p->month,
                'label'        => $p->label(),
                'status'       => $p->status,
                'status_label' => $p->statusLabel(),
                'status_color' => $p->statusColor(),
                'closed_at'    => $p->closed_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Accounting/AccountingPeriods/Index', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year'  => ['required', 'integer', 'min:2020', 'max:2099'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        AccountingPeriod::firstOrCreate(
            ['year' => $data['year'], 'month' => $data['month']],
            ['status' => 'open']
        );

        return back()->with('success', "Đã mở kỳ {$data['month']}/{$data['year']}.");
    }

    public function close(AccountingPeriod $accountingPeriod): RedirectResponse
    {
        if ($accountingPeriod->status !== 'open') {
            return back()->with('error', 'Kỳ kế toán không ở trạng thái mở.');
        }

        $accountingPeriod->update([
            'status'    => 'closed',
            'closed_at' => now(),
            'closed_by' => auth()->id(),
        ]);

        return back()->with('success', "Đã đóng kỳ {$accountingPeriod->label()}.");
    }

    public function lock(AccountingPeriod $accountingPeriod): RedirectResponse
    {
        if ($accountingPeriod->status !== 'closed') {
            return back()->with('error', 'Chỉ có thể khóa kỳ đã đóng.');
        }

        $accountingPeriod->update(['status' => 'locked']);

        return back()->with('success', "Đã khóa kỳ {$accountingPeriod->label()}.");
    }

    public function reopen(AccountingPeriod $accountingPeriod): RedirectResponse
    {
        if ($accountingPeriod->status === 'locked') {
            return back()->with('error', 'Kỳ đã khóa không thể mở lại. Cần quyền đặc biệt.');
        }

        $accountingPeriod->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);

        return back()->with('success', "Đã mở lại kỳ {$accountingPeriod->label()}.");
    }
}
