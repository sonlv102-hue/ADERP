<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\PeriodCloseBatch;
use App\Services\Accounting\PeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodCloseBatchController extends Controller
{
    public function __construct(private PeriodCloseService $service) {}

    public function index(): Response
    {
        $batches = PeriodCloseBatch::with(['creator', 'accountingPeriod'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($b) => $this->batchDto($b));

        $periods = AccountingPeriod::orderByDesc('year')->orderByDesc('month')
            ->get()
            ->map(fn ($p) => [
                'id'            => $p->id,
                'label'         => $p->label(),
                'fiscal_period' => sprintf('%04d-%02d', $p->year, $p->month),
                'status'        => $p->status,
                'status_label'  => $p->statusLabel(),
                'status_color'  => $p->statusColor(),
            ]);

        return Inertia::render('Accounting/PeriodClose/Index', [
            'batches' => $batches,
            'periods' => $periods,
        ]);
    }

    /** Dry-run: trả về preview plan + checklist + warnings, không ghi DB */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);

        try {
            $result = $this->service->preview($request->period);
        } catch (\Throwable $e) {
            return $this->errorJson('SERVER_ERROR', $e->getMessage());
        }

        // entryDate là Carbon object — serialize trước khi trả JSON
        $result['entryDate'] = $result['entryDate']->format('Y-m-d');

        return response()->json(array_merge(['success' => true], $result));
    }

    /** Tạo batch kết chuyển */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $batch = $this->service->closeWithBatch($data['period'], auth()->id(), $data['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period-close.show', $batch)
            ->with('success', "Kết chuyển kỳ {$data['period']} thành công. Batch: {$batch->code}");
    }

    /** Chi tiết một batch */
    public function show(PeriodCloseBatch $batch): Response
    {
        $batch->load(['creator', 'reversedByUser', 'accountingPeriod',
                      'journalEntries' => fn ($q) => $q->orderBy('id')->with('lines')]);

        return Inertia::render('Accounting/PeriodClose/Show', [
            'batch' => $this->batchDetailDto($batch),
        ]);
    }

    /** Đảo toàn bộ batch */
    public function reverse(Request $request, PeriodCloseBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->service->reverseBatch($batch, auth()->id(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period-close.show', $batch)
            ->with('success', "Đã đảo batch kết chuyển {$batch->code}.");
    }

    /** Xem trước kế hoạch chuyển lợi nhuận cuối năm (4212 → 4211) */
    public function yearEndPreview(Request $request): JsonResponse
    {
        $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2099']]);

        try {
            $plan = $this->service->buildYearEndTransfer((int) $request->year);
        } catch (\Throwable $e) {
            return $this->errorJson('SERVER_ERROR', $e->getMessage());
        }

        return response()->json(array_merge(['success' => true], $plan));
    }

    /** Tạo batch chuyển lợi nhuận cuối năm (4212 → 4211) */
    public function yearOpen(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year'  => ['required', 'integer', 'min:2020', 'max:2099'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $batch = $this->service->closeYearEnd((int) $data['year'], auth()->id(), $data['notes'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('period-close.show', $batch)
            ->with('success', "Đã chuyển lợi nhuận năm {$data['year']}. Batch: {$batch->code}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DTOs
    // ─────────────────────────────────────────────────────────────────────────

    private function batchDto(PeriodCloseBatch $b): array
    {
        return [
            'id'                  => $b->id,
            'code'                => $b->code,
            'fiscal_period'       => $b->fiscal_period,
            'batch_type'          => $b->batch_type ?? 'monthly',
            'period_label'        => $b->accountingPeriod?->label() ?? $b->fiscal_period,
            'status'              => $b->status,
            'status_label'        => $b->statusLabel(),
            'status_color'        => $b->statusColor(),
            'total_revenue'       => $b->total_revenue,
            'total_expense'       => $b->total_expense,
            'profit_or_loss'      => $b->profit_or_loss,
            'journal_entry_count' => $b->journal_entry_count,
            'notes'               => $b->notes,
            'created_by_name'     => $b->creator?->name ?? '—',
            'posted_at'           => $b->posted_at?->format('d/m/Y H:i'),
            'reversed_at'         => $b->reversed_at?->format('d/m/Y H:i'),
            'reverse_reason'      => $b->reverse_reason,
        ];
    }

    private function batchDetailDto(PeriodCloseBatch $b): array
    {
        $dto = $this->batchDto($b);
        $dto['journal_entries'] = $b->journalEntries->map(fn ($je) => [
            'id'           => $je->id,
            'code'         => $je->code,
            'description'  => $je->description,
            'status'       => $je->status,
            'status_label' => $je->statusLabel(),
            'status_color' => $je->statusColor(),
            'entry_date'   => $je->entry_date->format('d/m/Y'),
            'total_debit'  => (int) $je->lines->sum('debit'),
            'lines'        => $je->lines->map(fn ($l) => [
                'account_code' => $l->account_code,
                'description'  => $l->description,
                'debit'        => (int) $l->debit,
                'credit'       => (int) $l->credit,
            ]),
        ]);
        return $dto;
    }

    private function errorJson(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'success'    => false,
            'error_code' => $code,
            'message'    => $message,
        ], $status);
    }
}
