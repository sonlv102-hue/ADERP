<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalEntryController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function index(Request $request): Response
    {
        $query = JournalEntry::with('creator')
            ->withSum('lines as total_debit', 'debit')
            ->withSum('lines as total_credit', 'credit')
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $query->where(fn ($q) =>
                $q->where('code', 'ilike', "%{$request->search}%")
                  ->orWhere('description', 'ilike', "%{$request->search}%")
            );
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->where('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('entry_date', '<=', $request->to);
        }

        $draftCount = JournalEntry::where('status', 'draft')->count();

        return Inertia::render('Accounting/JournalEntries/Index', [
            'entries' => $query->paginate(30)->through(fn ($e) => [
                'id'           => $e->id,
                'code'         => $e->code,
                'entry_date'   => $e->entry_date->format('d/m/Y'),
                'description'  => $e->description,
                'status'       => $e->status,
                'status_label' => $e->statusLabel(),
                'status_color' => $e->statusColor(),
                'is_auto'      => $e->is_auto,
                'total_debit'  => (float) $e->total_debit,
                'total_credit' => (float) $e->total_credit,
                'creator'      => $e->creator?->name ?? 'Hệ thống',
            ]),
            'filters'    => $request->only(['search', 'status', 'from', 'to']),
            'draftCount' => $draftCount,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/JournalEntries/Form', [
            'nextCode' => JournalEntry::generateCode(),
            'accounts' => AccountCode::where('is_active', true)
                ->where('is_detail', true)
                ->orderBy('code')
                ->get(['code', 'name', 'type', 'normal_balance']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entry_date'          => ['required', 'date'],
            'description'         => ['required', 'string', 'max:500'],
            'notes'               => ['nullable', 'string', 'max:1000'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_code'=> ['required', 'exists:account_codes,code'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit'       => ['required', 'numeric', 'min:0'],
            'lines.*.credit'      => ['required', 'numeric', 'min:0'],
        ]);

        $lines = array_map(fn ($l) => [
            'account'     => $l['account_code'],
            'description' => $l['description'] ?? null,
            'debit'       => (int) $l['debit'],
            'credit'      => (int) $l['credit'],
        ], $data['lines']);

        try {
            $entry = $this->accounting->post(
                $data['description'],
                Carbon::parse($data['entry_date']),
                $lines,
                null, null, false,
                $data['notes'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal-entries.show', $entry)
            ->with('success', 'Đã hạch toán bút toán.');
    }

    public function show(JournalEntry $journalEntry): Response
    {
        $journalEntry->load('lines.account', 'creator');

        return Inertia::render('Accounting/JournalEntries/Show', [
            'entry' => [
                'id'             => $journalEntry->id,
                'code'           => $journalEntry->code,
                'entry_date'     => $journalEntry->entry_date->format('d/m/Y'),
                'description'    => $journalEntry->description,
                'reference_type' => $journalEntry->reference_type,
                'reference_id'   => $journalEntry->reference_id,
                'status'         => $journalEntry->status,
                'status_label'   => $journalEntry->statusLabel(),
                'status_color'   => $journalEntry->statusColor(),
                'is_auto'        => $journalEntry->is_auto,
                'notes'          => $journalEntry->notes,
                'creator'        => $journalEntry->creator?->name ?? 'Hệ thống',
                'posted_at'      => $journalEntry->posted_at?->format('d/m/Y H:i'),
                'total_debit'    => $journalEntry->totalDebit(),
                'total_credit'   => $journalEntry->totalCredit(),
                'lines'          => $journalEntry->lines->map(fn ($l) => [
                    'id'           => $l->id,
                    'account_code' => $l->account_code,
                    'account_name' => $l->account?->name ?? '—',
                    'description'  => $l->description,
                    'debit'        => (float) $l->debit,
                    'credit'       => (float) $l->credit,
                ]),
            ],
        ]);
    }

    public function markPosted(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $this->accounting->markPosted($journalEntry);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã duyệt và hạch toán bút toán {$journalEntry->code}.");
    }

    public function destroy(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('accounting.manage');

        // Không cho xóa bút toán tự động đang posted — phải dùng "Đảo bút toán"
        if ($journalEntry->is_auto && $journalEntry->status === 'posted') {
            return back()->with('error', 'Không thể xóa bút toán tự động đang hạch toán. Vui lòng dùng "Đảo bút toán" để hủy hiệu lực.');
        }

        $journalEntry->lines()->delete();
        $journalEntry->delete();

        return redirect()->route('accounting.journal-entries.index')
            ->with('success', "Đã xóa bút toán {$journalEntry->code}.");
    }

    public function reverse(JournalEntry $journalEntry, Request $request): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $reversal = $this->accounting->reverse($journalEntry, $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal-entries.show', $reversal)
            ->with('success', 'Đã tạo bút toán đảo.');
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $drafts = JournalEntry::where('status', 'draft')->get();
        $approved = 0;
        $errors = [];

        foreach ($drafts as $entry) {
            try {
                $this->accounting->markPosted($entry);
                $approved++;
            } catch (\RuntimeException $e) {
                $errors[] = "{$entry->code}: {$e->getMessage()}";
            }
        }

        $message = "Đã duyệt {$approved} bút toán.";
        if ($errors) {
            $message .= ' Lỗi: ' . implode('; ', array_slice($errors, 0, 3));
        }

        return back()->with('success', $message);
    }
}
