<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\PurchaseInvoice;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use App\Models\SmallToolReceipt;
use App\Models\SmallToolReceiptItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\SmallToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolReceiptController extends Controller
{
    public function __construct(protected SmallToolService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('ccdc.view');

        $receipts = SmallToolReceipt::with(['supplier', 'warehouse'])
            ->when($request->search, fn ($q, $s) =>
                $q->where(fn ($q2) => $q2->where('code', 'ilike', "%{$s}%"))
            )
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn ($r) => [
                'id'           => $r->id,
                'code'         => $r->code,
                'receipt_date' => $r->receipt_date->format('Y-m-d'),
                'supplier_name' => $r->supplier?->name,
                'warehouse_name' => $r->warehouse->name,
                'total_amount' => (float) $r->total_amount,
                'status'       => $r->status,
            ]);

        return Inertia::render('Accounting/SmallTools/Receipts/Index', [
            'receipts' => $receipts,
            'filters'  => $request->only(['search', 'status']),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('ccdc.manage');

        return Inertia::render('Accounting/SmallTools/Receipts/Form', [
            'nextCode'   => SmallToolReceipt::generateCode(),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'suppliers'  => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'funds'      => Fund::orderBy('name')->get(['id', 'name', 'type', 'account_code']),
            'categories' => SmallToolCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $request->validate([
            'receipt_date'        => 'required|date',
            'supplier_id'         => 'nullable|exists:suppliers,id',
            'purchase_invoice_id' => 'nullable|exists:purchase_invoices,id',
            'warehouse_id'        => 'required|exists:warehouses,id',
            'payment_type'        => 'required|in:payable,cash,bank',
            'fund_id'             => 'nullable|exists:funds,id',
            'notes'               => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.name'                    => 'required|string|max:255',
            'items.*.category_id'             => 'nullable|exists:small_tool_categories,id',
            'items.*.unit'                    => 'nullable|string|max:30',
            'items.*.quantity'                => 'required|integer|min:1',
            'items.*.unit_price'              => 'required|numeric|min:0',
            'items.*.vat_rate'                => 'nullable|numeric|min:0|max:100',
            'items.*.expense_account_code'    => 'nullable|string|max:20',
            'items.*.recognition_method'      => 'nullable|in:immediate,allocation',
            'items.*.allocation_periods'      => 'nullable|integer|min:1',
        ]);

        DB::transaction(function () use ($data, $request) {
            $totalCost  = 0;
            $totalVat   = 0;
            $createdItems = [];

            foreach ($data['items'] as $itemData) {
                $qty        = $itemData['quantity'];
                $unitPrice  = $itemData['unit_price'];
                $vatRate    = $itemData['vat_rate'] ?? 0;
                $lineCost   = round($qty * $unitPrice, 2);
                $lineVat    = round($lineCost * $vatRate / 100, 2);
                $lineTotal  = $lineCost + $lineVat;

                $totalCost += $lineCost;
                $totalVat  += $lineVat;

                // Tạo hồ sơ CCDC
                $tool = SmallTool::create([
                    'code'                 => SmallTool::generateCode(),
                    'name'                 => $itemData['name'],
                    'category_id'          => $itemData['category_id'] ?? null,
                    'unit'                 => $itemData['unit'] ?? 'cái',
                    'quantity'             => $qty,
                    'original_cost'        => $lineCost,
                    'vat_amount'           => $lineVat,
                    'total_cost'           => $lineTotal,
                    'acquisition_type'     => 'stock',
                    'recognition_method'   => $itemData['recognition_method'] ?? 'immediate',
                    'allocation_periods'   => $itemData['allocation_periods'] ?? null,
                    'expense_account_code' => $itemData['expense_account_code'] ?? '6422',
                    'payable_account_code' => $data['payment_type'] === 'payable' ? '3311' : '1111',
                    'payment_type'         => $data['payment_type'],
                    'fund_id'              => $data['fund_id'] ?? null,
                    'supplier_id'          => $data['supplier_id'] ?? null,
                    'status'               => 'draft',
                    'created_by'           => auth()->id(),
                ]);

                $createdItems[] = [
                    'small_tool_id' => $tool->id,
                    'quantity'      => $qty,
                    'unit_price'    => $unitPrice,
                    'vat_rate'      => $vatRate,
                    'vat_amount'    => $lineVat,
                    'total_amount'  => $lineTotal,
                ];
            }

            $receipt = SmallToolReceipt::create([
                'code'                => SmallToolReceipt::generateCode(),
                'receipt_date'        => $data['receipt_date'],
                'supplier_id'         => $data['supplier_id'] ?? null,
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'warehouse_id'        => $data['warehouse_id'],
                'payment_type'        => $data['payment_type'],
                'fund_id'             => $data['fund_id'] ?? null,
                'total_cost'          => round($totalCost, 2),
                'vat_amount'          => round($totalVat, 2),
                'total_amount'        => round($totalCost + $totalVat, 2),
                'status'              => 'draft',
                'notes'               => $data['notes'] ?? null,
                'created_by'          => auth()->id(),
            ]);

            foreach ($createdItems as $item) {
                SmallToolReceiptItem::create(array_merge($item, ['small_tool_receipt_id' => $receipt->id]));
            }

            // Auto-confirm nếu người dùng chọn
            if ($request->auto_confirm) {
                $this->service->confirmReceipt($receipt->fresh('items.tool', 'fund'));
                return redirect()->route('accounting.small-tool-receipts.show', $receipt)
                    ->with('success', 'Đã tạo và xác nhận phiếu nhập CCDC.');
            }
        });

        return redirect()->route('accounting.small-tool-receipts.index')
            ->with('success', 'Đã tạo phiếu nhập CCDC nháp.');
    }

    public function show(SmallToolReceipt $receipt): Response
    {
        $this->authorize('ccdc.view');

        $receipt->load('items.tool.category', 'supplier', 'warehouse', 'fund', 'journalEntry');

        return Inertia::render('Accounting/SmallTools/Receipts/Show', [
            'receipt' => [
                'id'              => $receipt->id,
                'code'            => $receipt->code,
                'receipt_date'    => $receipt->receipt_date->format('Y-m-d'),
                'supplier_name'   => $receipt->supplier?->name,
                'warehouse_name'  => $receipt->warehouse->name,
                'payment_type'    => $receipt->payment_type,
                'fund_name'       => $receipt->fund?->name,
                'total_cost'      => (float) $receipt->total_cost,
                'vat_amount'      => (float) $receipt->vat_amount,
                'total_amount'    => (float) $receipt->total_amount,
                'status'          => $receipt->status,
                'notes'           => $receipt->notes,
                'journal_entry_id' => $receipt->journal_entry_id,
                'items'           => $receipt->items->map(fn ($i) => [
                    'id'            => $i->id,
                    'tool_code'     => $i->tool->code,
                    'tool_name'     => $i->tool->name,
                    'tool_id'       => $i->tool->id,
                    'category_name' => $i->tool->category?->name,
                    'unit'          => $i->tool->unit,
                    'quantity'      => $i->quantity,
                    'unit_price'    => (float) $i->unit_price,
                    'vat_rate'      => (float) $i->vat_rate,
                    'vat_amount'    => (float) $i->vat_amount,
                    'total_amount'  => (float) $i->total_amount,
                    'tool_status'   => $i->tool->status->value,
                ]),
            ],
        ]);
    }

    public function confirm(SmallToolReceipt $receipt): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        try {
            $this->service->confirmReceipt($receipt->load('items.tool', 'fund'));
            return back()->with('success', 'Đã xác nhận phiếu nhập CCDC và tạo bút toán.');
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function cancel(SmallToolReceipt $receipt): RedirectResponse
    {
        $this->authorize('ccdc.cancel');

        try {
            $this->service->recallReceipt($receipt->load('items.tool'));
            return back()->with('success', 'Đã hủy phiếu nhập CCDC.');
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
