<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OpeningBalanceController extends Controller
{
    public function index(): Response
    {
        // Lấy bút toán số dư đầu kỳ hiện tại (nếu có)
        $entry = JournalEntry::with('lines')
            ->where('reference_type', 'opening_balance')
            ->orderByDesc('entry_date')
            ->first();

        $existingLines = $entry
            ? $entry->lines->keyBy('account_code')
            : collect();

        // Lấy tất cả tài khoản chi tiết đang hoạt động
        $accounts = AccountCode::where('is_detail', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn ($a) => [
                'code'           => $a->code,
                'name'           => $a->name,
                'type'           => $a->type,
                'type_label'     => $a->typeLabel(),
                'normal_balance' => $a->normal_balance,
                'debit'          => (int) ($existingLines[$a->code]?->debit  ?? 0),
                'credit'         => (int) ($existingLines[$a->code]?->credit ?? 0),
            ]);

        return Inertia::render('Accounting/OpeningBalance/Index', [
            'accounts'   => $accounts,
            'entry_date' => $entry?->entry_date?->format('Y-m-d')
                ?? now()->startOfYear()->format('Y-m-d'),
            'has_entry'  => (bool) $entry,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'entry_date'              => ['required', 'date'],
            'lines'                   => ['required', 'array'],
            'lines.*.account_code'    => ['required', 'exists:account_codes,code'],
            'lines.*.debit'           => ['required', 'numeric', 'min:0'],
            'lines.*.credit'          => ['required', 'numeric', 'min:0'],
        ]);

        $lines = array_values(array_filter(
            $request->input('lines'),
            fn ($l) => ((int) $l['debit']) > 0 || ((int) $l['credit']) > 0
        ));

        DB::transaction(function () use ($request, $lines) {
            // Xóa bút toán cũ (nếu có)
            JournalEntry::where('reference_type', 'opening_balance')->delete();

            if (empty($lines)) return;

            $entry = JournalEntry::create([
                'code'           => 'SDDK',
                'entry_date'     => $request->input('entry_date'),
                'description'    => 'Số dư đầu kỳ',
                'reference_type' => 'opening_balance',
                'status'         => 'posted',
                'is_auto'        => false,
                'created_by'     => auth()->id(),
                'posted_at'      => now(),
            ]);

            foreach ($lines as $idx => $line) {
                $entry->lines()->create([
                    'account_code' => $line['account_code'],
                    'debit'        => (int) $line['debit'],
                    'credit'       => (int) $line['credit'],
                    'description'  => 'Số dư đầu kỳ',
                    'sort_order'   => $idx + 1,
                ]);
            }
        });

        return back()->with('success', 'Đã lưu số dư đầu kỳ thành công.');
    }
}
