<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseQuoteComparisonStatus;
use App\Enums\QuoteSelectionReason;
use App\Exports\PurchaseQuoteComparisonResultExport;
use App\Exports\PurchaseQuoteTemplateExport;
use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Models\PurchaseQuoteComparison;
use App\Models\PurchaseQuoteComparisonItem;
use App\Models\PurchaseSupplierQuote;
use App\Services\PurchaseQuoteComparisonMatrixService;
use App\Services\PurchaseQuoteComparisonService;
use App\Services\PurchaseQuoteImportService;
use App\Services\PurchaseQuoteSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class PurchaseQuoteComparisonController extends Controller
{
    public function __construct(
        private PurchaseQuoteComparisonService $service,
        private PurchaseQuoteImportService $importService,
        private PurchaseQuoteComparisonMatrixService $matrixService,
        private PurchaseQuoteSelectionService $selectionService,
    ) {}

    public function index(Request $request): Response
    {
        $query = PurchaseQuoteComparison::query()
            ->withCount(['items', 'activeQuotes as active_quotes_count'])
            ->with('creator:id,name')
            ->orderByDesc('id');

        if ($search = $request->string('search')->trim()->toString()) {
            $needle = mb_strtolower($search);
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(code) LIKE ?', ["%{$needle}%"])
                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$needle}%"]));
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return Inertia::render('Purchasing/QuoteComparisons/Index', [
            'comparisons' => $query->paginate(20)->through(fn ($c) => [
                'id'            => $c->id,
                'code'          => $c->code,
                'name'          => $c->name,
                'comparison_date' => $c->comparison_date->format('d/m/Y'),
                'created_at'    => $c->created_at->format('d/m/Y'),
                'supplier_count' => $c->active_quotes_count,
                'item_count'    => $c->items_count,
                'status'        => $c->status->value,
                'status_label'  => $c->status->label(),
                'status_color'  => $c->status->color(),
                'creator'       => $c->creator->name ?? '—',
            ]),
            'filters'  => ['search' => $request->input('search'), 'status' => $request->input('status')],
            'statuses' => collect(PurchaseQuoteComparisonStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Purchasing/QuoteComparisons/Create', [
            'nextCode' => 'SSBG-' . now()->year . '-XXXXX',
            'users'    => \App\Models\User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'comparison_date' => 'required|date',
            'department'      => 'nullable|string|max:150',
            'project_id'      => 'nullable|exists:projects,id',
            'buyer_id'        => 'nullable|exists:users,id',
            'note'            => 'nullable|string|max:2000',
        ]);

        $comparison = $this->service->create($data);
        activity()->performedOn($comparison)->log('Tạo đợt so sánh báo giá NCC');

        return redirect()->route('purchasing.quote-comparisons.show', $comparison)
            ->with('success', 'Đã tạo đợt so sánh báo giá ' . $comparison->code);
    }

    public function show(Request $request, PurchaseQuoteComparison $quoteComparison): Response
    {
        return Inertia::render('Purchasing/QuoteComparisons/Show', $this->showProps(
            $quoteComparison,
            $request->string('price_basis')->toString() ?: 'net'
        ));
    }

    public function update(Request $request, PurchaseQuoteComparison $quoteComparison): RedirectResponse
    {
        $action = $request->input('action', 'header');

        try {
            if ($action === 'complete') {
                $this->service->markCompleted($quoteComparison);
                $msg = 'Đã đánh dấu hoàn thành đợt so sánh.';
            } elseif ($action === 'reopen') {
                $this->service->reopen($quoteComparison);
                $msg = 'Đã mở lại đợt so sánh.';
            } else {
                $data = $request->validate([
                    'name'            => 'required|string|max:255',
                    'comparison_date' => 'required|date',
                    'department'      => 'nullable|string|max:150',
                    'project_id'      => 'nullable|exists:projects,id',
                    'buyer_id'        => 'nullable|exists:users,id',
                    'note'            => 'nullable|string|max:2000',
                ]);
                $this->service->updateHeader($quoteComparison, $data);
                $msg = 'Đã cập nhật thông tin đợt so sánh.';
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $msg);
    }

    public function destroy(PurchaseQuoteComparison $quoteComparison): RedirectResponse
    {
        if ($quoteComparison->status !== PurchaseQuoteComparisonStatus::Draft) {
            return back()->with('error', 'Chỉ có thể xóa đợt so sánh ở trạng thái Nháp.');
        }
        $quoteComparison->delete();
        activity()->performedOn($quoteComparison)->log('Xóa đợt so sánh báo giá NCC');

        return redirect()->route('purchasing.quote-comparisons.index')->with('success', 'Đã xóa đợt so sánh.');
    }

    // ── Tab 1: danh sách hàng ────────────────────────────────────────────────

    public function addItem(Request $request, PurchaseQuoteComparison $quoteComparison): RedirectResponse
    {
        $data = $request->validate([
            'product_id'    => 'required|exists:products,id',
            'requested_qty' => 'required|numeric|min:0.01',
            'specification' => 'nullable|string|max:255',
            'note'          => 'nullable|string|max:1000',
        ]);

        try {
            $this->service->addItem($quoteComparison, (int) $data['product_id'], (float) $data['requested_qty'], $data['specification'] ?? null, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã thêm mặt hàng.');
    }

    public function removeItem(PurchaseQuoteComparison $quoteComparison, PurchaseQuoteComparisonItem $item): RedirectResponse
    {
        abort_unless($item->comparison_id === $quoteComparison->id, 404);

        try {
            $this->service->removeItem($item);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xóa mặt hàng.');
    }

    public function itemsTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $headers = ['Mã hàng', 'SL yêu cầu', 'Quy cách', 'Ghi chú'];
        $sample = [
            ['[Xóa dòng hướng dẫn này] Nhập Mã hàng (SP-xxxx) và SL yêu cầu. Quy cách/Ghi chú tùy chọn.'],
            ['SP-0001', 10, 'Cuộn 100m', ''],
        ];

        return Excel::download(new TemplateExport($headers, 'Danh sach hang', $sample), 'mau-danh-sach-hang.xlsx');
    }

    public function importItems(Request $request, PurchaseQuoteComparison $quoteComparison): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        try {
            $result = $this->service->importItems($quoteComparison, $request->file('file'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = "Đã thêm {$result['created']} mặt hàng" . ($result['skipped'] ? ", bỏ qua {$result['skipped']} trùng" : '') . '.';
        if ($result['errors']) {
            return back()->with('warning', $msg . ' Lỗi: ' . implode(' | ', array_slice($result['errors'], 0, 5)));
        }

        return back()->with('success', $msg);
    }

    // ── Tab 2: báo giá NCC ───────────────────────────────────────────────────

    public function quoteTemplate(PurchaseQuoteComparison $quoteComparison): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return Excel::download(new PurchaseQuoteTemplateExport($quoteComparison), "mau-bao-gia-{$quoteComparison->code}.xlsx");
    }

    public function previewQuote(Request $request, PurchaseQuoteComparison $quoteComparison): JsonResponse
    {
        $data = $request->validate([
            'file'          => 'required|file|mimes:xlsx|max:10240',
            'supplier_id'   => 'required|exists:suppliers,id',
            'quote_no'      => 'nullable|string|max:100',
            'quote_date'    => 'nullable|date',
            'valid_until'   => 'nullable|date',
            'currency'      => 'nullable|string|max:10',
            'payment_terms' => 'nullable|string|max:255',
            'shipping_fee'  => 'nullable|numeric|min:0',
            'note'          => 'nullable|string|max:1000',
        ]);

        try {
            $meta = collect($data)->except('file')->toArray();
            $result = $this->importService->previewQuote($quoteComparison, $request->file('file'), $meta);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function confirmQuote(Request $request, PurchaseQuoteComparison $quoteComparison): JsonResponse
    {
        $request->validate(['preview_id' => 'required|string']);

        try {
            $quote = $this->importService->confirmQuote($request->string('preview_id')->toString());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Đã import báo giá (phiên bản {$quote->version_no}).", 'quote_id' => $quote->id]);
    }

    public function downloadQuoteFile(PurchaseQuoteComparison $quoteComparison, PurchaseSupplierQuote $quote): mixed
    {
        abort_unless($quote->comparison_id === $quoteComparison->id, 404);
        abort_unless($quote->stored_file_path && Storage::disk('local')->exists($quote->stored_file_path), 404, 'File gốc không còn tồn tại.');

        return Storage::disk('local')->download($quote->stored_file_path, $quote->original_filename ?? 'bao-gia.xlsx');
    }

    public function deleteQuote(PurchaseQuoteComparison $quoteComparison, PurchaseSupplierQuote $quote): RedirectResponse
    {
        abort_unless($quote->comparison_id === $quoteComparison->id, 404);

        try {
            $this->importService->deleteQuote($quote);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        activity()->performedOn($quote)->log('Gỡ báo giá NCC khỏi đợt so sánh');

        return back()->with('success', 'Đã gỡ báo giá NCC.');
    }

    // ── Tab 3: lựa chọn NCC ──────────────────────────────────────────────────

    public function setSelection(Request $request, PurchaseQuoteComparison $quoteComparison, PurchaseQuoteComparisonItem $item): RedirectResponse
    {
        abort_unless($item->comparison_id === $quoteComparison->id, 404);

        $data = $request->validate([
            'quote_line_id'    => 'nullable|exists:purchase_supplier_quote_lines,id',
            'selection_reason' => 'nullable|string|max:40',
            'selection_note'   => 'nullable|string|max:1000',
        ]);

        try {
            if (empty($data['quote_line_id'])) {
                $this->selectionService->clearSelection($item);
                return back()->with('success', 'Đã bỏ chọn NCC cho mặt hàng.');
            }
            $this->selectionService->setSelection($item, (int) $data['quote_line_id'], $data['selection_reason'] ?? null, $data['selection_note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã lưu lựa chọn NCC.');
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public function exportResult(Request $request, PurchaseQuoteComparison $quoteComparison): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $basis = $request->string('price_basis')->toString() ?: 'net';
        $matrix = $this->matrixService->buildMatrix($quoteComparison, $basis);
        $summary = $this->matrixService->selectionSummary($quoteComparison);

        return Excel::download(
            new PurchaseQuoteComparisonResultExport($quoteComparison, $matrix, $summary),
            "so-sanh-bao-gia-{$quoteComparison->code}.xlsx"
        );
    }

    // ── DTO ──────────────────────────────────────────────────────────────────

    private function showProps(PurchaseQuoteComparison $c, string $priceBasis): array
    {
        $c->load(['items.selection', 'project:id,code,name', 'buyer:id,name', 'creator:id,name', 'quotes.supplier:id,code,name']);

        return [
            'comparison' => [
                'id'              => $c->id,
                'code'            => $c->code,
                'name'            => $c->name,
                'comparison_date' => $c->comparison_date->format('Y-m-d'),
                'department'      => $c->department,
                'project'         => $c->project ? ['id' => $c->project->id, 'code' => $c->project->code, 'name' => $c->project->name] : null,
                'buyer'           => $c->buyer?->name,
                'buyer_id'        => $c->buyer_id,
                'project_id'      => $c->project_id,
                'note'            => $c->note,
                'creator'         => $c->creator->name ?? '—',
                'created_at'      => $c->created_at->format('d/m/Y H:i'),
                'status'          => $c->status->value,
                'status_label'    => $c->status->label(),
                'status_color'    => $c->status->color(),
                'is_draft'        => $c->status === PurchaseQuoteComparisonStatus::Draft,
            ],
            'items' => $c->items->map(fn ($i) => [
                'id'            => $i->id,
                'product_id'    => $i->product_id,
                'product_code'  => $i->product_code_snapshot,
                'product_name'  => $i->product_name_snapshot,
                'unit'          => $i->unit_snapshot,
                'specification' => $i->specification,
                'requested_qty' => (float) $i->requested_qty,
                'note'          => $i->note,
                'has_quote_lines' => $i->quoteLines()->exists(),
            ]),
            'quotes' => $c->quotes->sortByDesc('id')->values()->map(fn ($q) => [
                'id'                => $q->id,
                'supplier'          => $q->supplier->name ?? '—',
                'supplier_code'     => $q->supplier->code ?? '',
                'quote_no'          => $q->quote_no,
                'quote_date'        => optional($q->quote_date)->format('d/m/Y'),
                'valid_until'       => optional($q->valid_until)->format('d/m/Y'),
                'currency'          => $q->currency,
                'version_no'        => $q->version_no,
                'is_active_version' => $q->is_active_version,
                'line_count'        => $q->lines()->count(),
                'item_count'        => $c->items->count(),
                'totals'            => $q->lineTotals(),
                'shipping_fee'      => (float) $q->shipping_fee,
                'original_filename' => $q->original_filename,
                'has_file'          => (bool) $q->stored_file_path,
            ]),
            'matrix'     => $this->matrixService->buildMatrix($c, $priceBasis),
            'summary'    => $this->matrixService->selectionSummary($c),
            'reasons'    => QuoteSelectionReason::options(),
            'priceBasis' => $priceBasis,
        ];
    }
}
