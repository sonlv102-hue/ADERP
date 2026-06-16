<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ProjectWipEntry;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'entry'    => null,
            'nextCode' => JournalEntry::generateCode(),
            'accounts' => AccountCode::where('is_active', true)
                ->where('is_detail', true)
                ->orderBy('code')
                ->get(['code', 'name', 'type', 'normal_balance']),
        ]);
    }

    public function edit(JournalEntry $journalEntry): Response|RedirectResponse
    {
        if ($journalEntry->status !== 'draft') {
            return redirect()->route('accounting.journal-entries.show', $journalEntry)
                ->with('error', 'Chỉ có thể sửa bút toán ở trạng thái Nháp.');
        }

        $journalEntry->load('lines');

        return Inertia::render('Accounting/JournalEntries/Form', [
            'entry'    => [
                'id'             => $journalEntry->id,
                'code'           => $journalEntry->code,
                'entry_date'     => $journalEntry->entry_date->format('Y-m-d'),
                'description'    => $journalEntry->description,
                'notes'          => $journalEntry->notes,
                'is_auto'        => $journalEntry->is_auto,
                'edited_by_user' => $journalEntry->edited_by_user,
                'lines'          => $journalEntry->lines->map(fn ($l) => [
                    'account_code' => $l->account_code,
                    'description'  => $l->description,
                    'debit'        => (float) $l->debit,
                    'credit'       => (float) $l->credit,
                ])->values()->toArray(),
            ],
            'nextCode' => null,
            'accounts' => AccountCode::where('is_active', true)
                ->where('is_detail', true)
                ->orderBy('code')
                ->get(['code', 'name', 'type', 'normal_balance']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'entry_date'           => ['required', 'date'],
            'description'          => ['required', 'string', 'max:500'],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'save_as_draft'        => ['nullable', 'boolean'],
            'lines'                => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['required', 'exists:account_codes,code'],
            'lines.*.description'  => ['nullable', 'string', 'max:500'],
            'lines.*.debit'        => ['required', 'numeric', 'min:0'],
            'lines.*.credit'       => ['required', 'numeric', 'min:0'],
        ]);

        $lines = array_map(fn ($l) => [
            'account'     => $l['account_code'],
            'description' => $l['description'] ?? null,
            'debit'       => (int) $l['debit'],
            'credit'      => (int) $l['credit'],
        ], $data['lines']);

        try {
            if ($request->boolean('save_as_draft')) {
                $entry = $this->accounting->createDraft(
                    $data['description'],
                    Carbon::parse($data['entry_date']),
                    $lines,
                    $data['notes'] ?? null
                );
                $msg = 'Đã lưu bút toán nháp.';
            } else {
                $entry = $this->accounting->post(
                    $data['description'],
                    Carbon::parse($data['entry_date']),
                    $lines,
                    null, null, false,
                    $data['notes'] ?? null
                );
                $msg = 'Đã hạch toán bút toán.';
            }
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('accounting.journal-entries.show', $entry)->with('success', $msg);
    }

    public function show(JournalEntry $journalEntry): Response
    {
        $journalEntry->load('lines.account', 'creator', 'voidedBy');

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
                'edited_by_user' => $journalEntry->edited_by_user,
                'edit_reason'    => $journalEntry->edit_reason,
                'has_original'   => $journalEntry->original_lines !== null,
                'notes'          => $journalEntry->notes,
                'creator'        => $journalEntry->creator?->name ?? 'Hệ thống',
                'posted_at'      => $journalEntry->posted_at?->format('d/m/Y H:i'),
                'voided_at'      => $journalEntry->voided_at?->format('d/m/Y H:i'),
                'voided_by'      => $journalEntry->voidedBy?->name,
                'void_reason'    => $journalEntry->void_reason,
                'period_locked'  => $this->isPeriodLocked($journalEntry),
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

    public function update(Request $request, JournalEntry $journalEntry): RedirectResponse
    {
        $data = $request->validate([
            'description'          => ['required', 'string', 'max:500'],
            'notes'                => ['nullable', 'string', 'max:1000'],
            'edit_reason'          => ['nullable', 'string', 'max:500'],
            'lines'                => ['nullable', 'array', 'min:2'],
            'lines.*.account_code' => ['required_with:lines', 'exists:account_codes,code'],
            'lines.*.description'  => ['nullable', 'string', 'max:500'],
            'lines.*.debit'        => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.credit'       => ['required_with:lines', 'numeric', 'min:0'],
        ]);

        $journalEntry->update([
            'description' => $data['description'],
            'notes'       => $data['notes'] ?? null,
        ]);

        if (! empty($data['lines'])) {
            if ($journalEntry->status !== 'draft') {
                return back()->with('error', 'Chỉ có thể sửa dòng bút toán khi ở trạng thái Nháp.');
            }
            $lines = array_map(fn ($l) => [
                'account'     => $l['account_code'],
                'description' => $l['description'] ?? null,
                'debit'       => (int) $l['debit'],
                'credit'      => (int) $l['credit'],
            ], $data['lines']);
            try {
                $this->accounting->updateLines($journalEntry, $lines, $data['edit_reason'] ?? null);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        return redirect()->route('accounting.journal-entries.show', $journalEntry)
            ->with('success', 'Đã cập nhật bút toán.');
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

    public function unpost(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $this->accounting->unpost($journalEntry);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Đã thu hồi hạch toán. Bút toán {$journalEntry->code} về trạng thái Nháp.");
    }

    public function restoreOriginal(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('accounting.manage');

        try {
            $this->accounting->restoreOriginalLines($journalEntry);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã khôi phục dòng bút toán về trạng thái ban đầu.');
    }

    public function destroy(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('accounting.manage');

        if ($journalEntry->status === 'draft') {
            DB::transaction(function () use ($journalEntry) {
                ProjectWipEntry::where('journal_entry_id', $journalEntry->id)->update(['journal_entry_id' => null]);
                $journalEntry->lines()->delete();
                $journalEntry->delete();
            });

            return redirect()->route('accounting.journal-entries.index')
                ->with('success', "Đã xóa bút toán {$journalEntry->code}.");
        }

        if ($journalEntry->status === 'voided') {
            if (! auth()->user()->hasRole('admin')) {
                return back()->with('error', 'Chỉ admin mới có thể xóa bút toán đã hủy.');
            }

            $codes = [];

            DB::transaction(function () use ($journalEntry, &$codes) {
                // Tìm cặp (nếu có): original có reversed_by_id → reversal; reversal không có
                $pair = $journalEntry->reversed_by_id
                    ? JournalEntry::find($journalEntry->reversed_by_id)
                    : JournalEntry::where('reversed_by_id', $journalEntry->id)->first();

                $ids = collect([$journalEntry->id]);
                $codes = [$journalEntry->code];

                if ($pair) {
                    $ids->push($pair->id);
                    $codes[] = $pair->code;
                }

                // Xử lý FK không có nullOnDelete
                ProjectWipEntry::whereIn('journal_entry_id', $ids)->update(['journal_entry_id' => null]);

                // Bỏ self-reference trước khi xóa
                JournalEntry::whereIn('reversed_by_id', $ids)->update(['reversed_by_id' => null]);

                // Lines cascade, nhưng xóa tường minh cho chắc
                JournalEntryLine::whereIn('journal_entry_id', $ids)->delete();

                JournalEntry::whereIn('id', $ids)->delete();
            });

            $label = implode(' + ', $codes);

            return redirect()->route('accounting.journal-entries.index')
                ->with('success', "Đã xóa bút toán đã hủy: {$label}.");
        }

        return back()->with('error', 'Chỉ có thể xóa bút toán ở trạng thái Nháp hoặc Đã hủy (admin).');
    }

    /**
     * Hủy bút toán đã ghi sổ (posted hoặc reversed).
     * - posted: hủy đơn lẻ
     * - reversed: hủy cả cặp (gốc + đảo ngược)
     */
    public function void(JournalEntry $journalEntry, Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'void_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($journalEntry->status === 'draft') {
            return back()->with('error', 'Bút toán nháp không cần hủy — dùng "Xóa" để xóa khỏi hệ thống.');
        }

        if ($journalEntry->status === 'voided') {
            return back()->with('error', 'Bút toán này đã được hủy trước đó.');
        }

        if ($this->isPeriodLocked($journalEntry)) {
            return back()->with('error', 'Kỳ kế toán của bút toán này đã khóa sổ. Vui lòng lập bút toán điều chỉnh ở kỳ hiện tại.');
        }

        $voidData = [
            'status'      => 'voided',
            'voided_at'   => now(),
            'voided_by'   => auth()->id(),
            'void_reason' => $data['void_reason'] ?? null,
        ];

        if ($journalEntry->status === 'reversed') {
            $reversalEntry = $journalEntry->reversedBy;

            if (! $reversalEntry) {
                return back()->with('error', 'Không xác định được bút toán đảo ngược đi kèm. Không thể hủy.');
            }

            DB::transaction(function () use ($journalEntry, $reversalEntry, $voidData) {
                $journalEntry->update($voidData);
                $reversalEntry->update($voidData);
            });

            return redirect()->route('accounting.journal-entries.index')
                ->with('success', 'Đã hủy cặp bút toán thành công. Các bút toán này không còn ảnh hưởng đến báo cáo, nhưng vẫn được lưu trong lịch sử.');
        }

        $journalEntry->update($voidData);

        return back()->with('success', "Đã hủy bút toán {$journalEntry->code}. Bút toán không còn ảnh hưởng đến báo cáo, nhưng vẫn được lưu trong lịch sử.");
    }

    private function isPeriodLocked(JournalEntry $entry): bool
    {
        $date   = $entry->entry_date;
        $period = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        return $period && $period->status === 'locked';
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

        $drafts   = JournalEntry::where('status', 'draft')->with('lines')->get();
        $approved = 0;
        $errors   = [];

        foreach ($drafts as $entry) {
            if ($entry->lines->isEmpty()) {
                $errors[] = "{$entry->code}: Không có dòng bút toán.";
                continue;
            }
            if (! $entry->isBalanced()) {
                $errors[] = "{$entry->code}: Bút toán không cân (Nợ ≠ Có).";
                continue;
            }
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
            if (count($errors) > 3) {
                $message .= ' ... và ' . (count($errors) - 3) . ' lỗi khác.';
            }
        }

        return back()->with('success', $message);
    }
}
