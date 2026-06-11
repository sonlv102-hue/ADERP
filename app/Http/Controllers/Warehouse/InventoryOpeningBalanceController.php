<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\InventoryOpeningBalance;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryOpeningBalanceController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function index(Request $request): Response
    {
        $period    = $request->input('period', now()->format('Y-m'));
        $warehouse = $request->input('warehouse_id');

        $query = InventoryOpeningBalance::with(['product', 'warehouse', 'creator'])
            ->where('period', $period)
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse))
            ->orderBy('warehouse_id')
            ->orderBy('product_id');

        return Inertia::render('Warehouse/OpeningBalance/Index', [
            'balances'   => $query->get()->map(fn ($b) => [
                'id'             => $b->id,
                'period'         => $b->period,
                'warehouse_name' => $b->warehouse?->name,
                'product_code'   => $b->product?->code,
                'product_name'   => $b->product?->name,
                'unit'           => $b->product?->unit,
                'quantity'       => (float) $b->quantity,
                'unit_cost'      => (float) $b->unit_cost,
                'total_cost'     => (float) $b->total_cost,
                'note'           => $b->note,
                'creator'        => $b->creator?->name,
                'has_je'         => (bool) $b->journal_entry_id,
            ]),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'filters'    => ['period' => $period, 'warehouse_id' => $warehouse],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouse/OpeningBalance/Form', [
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'products'   => Product::where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'cost_price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period'       => 'required|string|regex:/^\d{4}-\d{2}$/',
            'warehouse_id' => 'required|exists:warehouses,id',
            'items'        => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|numeric|min:0',
            'items.*.unit_cost'  => 'required|numeric|min:0',
            'items.*.note'       => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($data) {
            $date = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth();
            // Đặt movement vào cuối tháng trước để luôn nằm trong stock_begin khi date_from = đầu tháng mở đầu kỳ
            $movDate = $date->copy()->subSecond();
            $lines = [];
            $totalCost = 0;

            foreach ($data['items'] as $item) {
                $qty   = (float) $item['quantity'];
                $cost  = (float) $item['unit_cost'];
                $total = round($qty * $cost, 2);
                $totalCost += $total;

                $balance = InventoryOpeningBalance::updateOrCreate(
                    [
                        'period'       => $data['period'],
                        'warehouse_id' => $data['warehouse_id'],
                        'product_id'   => $item['product_id'],
                    ],
                    [
                        'quantity'   => $qty,
                        'unit_cost'  => $cost,
                        'total_cost' => $total,
                        'note'       => $item['note'] ?? null,
                        'created_by' => auth()->id(),
                    ]
                );

                // Đồng bộ stock_movement — xóa cũ rồi tạo mới (idempotent khi re-submit)
                DB::table('stock_movements')
                    ->where('source_type', InventoryOpeningBalance::class)
                    ->where('source_id', $balance->id)
                    ->delete();

                if ($qty > 0) {
                    DB::table('stock_movements')->insert([
                        'warehouse_id' => $data['warehouse_id'],
                        'product_id'   => $item['product_id'],
                        'quantity'     => $qty,
                        'type'         => 'opening',
                        'source_type'  => InventoryOpeningBalance::class,
                        'source_id'    => $balance->id,
                        'created_by'   => auth()->id(),
                        'notes'        => "Tồn kho đầu kỳ {$data['period']}",
                        'created_at'   => $movDate,
                        'updated_at'   => now(),
                    ]);
                }

                $product = Product::find($item['product_id']);

                // Sync cost_price: chỉ update khi cost_price hiện tại = 0/null và unit_cost > 0
                if ($cost > 0 && (is_null($product->cost_price) || (float) $product->cost_price == 0.0)) {
                    $product->cost_price = $cost;
                    $product->save();
                    \Illuminate\Support\Facades\Log::info('InventoryOpeningBalance: auto-set cost_price', [
                        'product_id'   => $product->id,
                        'product_code' => $product->code,
                        'cost_price'   => $cost,
                        'period'       => $data['period'],
                        'by'           => auth()->id(),
                    ]);
                }

                $lines[] = [
                    'account'     => '156',   // TK 156 is_detail=true trong hệ thống này
                    'debit'       => $total,
                    'credit'      => 0,
                    'description' => "Tồn ĐK {$product->name} tháng {$data['period']}",
                ];
            }

            if ($totalCost > 0) {
                // Cr 4111 — vốn đầu tư của chủ sở hữu (leaf account, TK 411 là tổng hợp)
                $lines[] = [
                    'account'     => '4111',
                    'debit'       => 0,
                    'credit'      => round($totalCost),
                    'description' => "Tồn kho đầu kỳ {$data['period']}",
                ];

                $this->accounting->post(
                    description: "Nhập tồn kho đầu kỳ {$data['period']} — Kho " .
                        Warehouse::find($data['warehouse_id'])->name,
                    date: $date,
                    lines: $lines,
                    referenceType: InventoryOpeningBalance::class,
                    referenceId: 0,
                    isAuto: false,
                    notes: null,
                    journalSourceType: 'inventory_opening',
                    excludeFromPeriodMovement: true,
                    fiscalPeriod: $data['period'],
                );
            }
        });

        return redirect()->route('warehouse.opening-balance.index')
            ->with('success', 'Đã nhập tồn kho đầu kỳ thành công.');
    }

    public function destroy(InventoryOpeningBalance $openingBalance): RedirectResponse
    {
        if ($openingBalance->journal_entry_id) {
            return back()->with('error', 'Không thể xóa dòng đã có bút toán kế toán.');
        }
        DB::transaction(function () use ($openingBalance) {
            DB::table('stock_movements')
                ->where('source_type', InventoryOpeningBalance::class)
                ->where('source_id', $openingBalance->id)
                ->delete();
            $openingBalance->delete();
        });
        return back()->with('success', 'Đã xóa.');
    }
}
