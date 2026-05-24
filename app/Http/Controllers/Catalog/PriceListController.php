<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Catalog/PriceLists/Index', [
            'priceLists' => PriceList::with('creator')
                ->withCount('items')
                ->orderByDesc('id')
                ->paginate(20)
                ->through(fn ($pl) => [
                    'id'          => $pl->id,
                    'code'        => $pl->code,
                    'name'        => $pl->name,
                    'valid_from'  => $pl->valid_from?->format('d/m/Y'),
                    'valid_to'    => $pl->valid_to?->format('d/m/Y'),
                    'is_default'  => $pl->is_default,
                    'items_count' => $pl->items_count,
                    'creator'     => $pl->creator->name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/PriceLists/Form', [
            'nextCode' => PriceList::generateCode(),
            'products' => Product::where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'sell_price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:150'],
            'valid_from' => ['nullable', 'date'],
            'valid_to'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_default' => ['boolean'],
            'notes'      => ['nullable', 'string'],
            'items'      => ['array'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $data['code']       = PriceList::generateCode();
        $data['created_by'] = auth()->id();

        $items = $data['items'] ?? [];
        unset($data['items']);

        $priceList = PriceList::create($data);

        foreach ($items as $item) {
            PriceListItem::create([
                'price_list_id' => $priceList->id,
                'product_id'    => $item['product_id'],
                'unit_price'    => $item['unit_price'],
            ]);
        }

        return redirect()->route('catalog.price-lists.index')
            ->with('success', 'Đã tạo bảng giá.');
    }

    public function show(PriceList $priceList): Response
    {
        $priceList->load(['items.product', 'creator']);

        return Inertia::render('Catalog/PriceLists/Show', [
            'priceList' => [
                'id'         => $priceList->id,
                'code'       => $priceList->code,
                'name'       => $priceList->name,
                'valid_from' => $priceList->valid_from?->format('d/m/Y'),
                'valid_to'   => $priceList->valid_to?->format('d/m/Y'),
                'is_default' => $priceList->is_default,
                'notes'      => $priceList->notes,
                'creator'    => $priceList->creator->name,
                'items'      => $priceList->items->map(fn ($i) => [
                    'id'         => $i->id,
                    'product_id' => $i->product_id,
                    'product'    => ['id' => $i->product->id, 'code' => $i->product->code, 'name' => $i->product->name, 'unit' => $i->product->unit],
                    'unit_price' => $i->unit_price,
                ]),
            ],
        ]);
    }

    public function edit(PriceList $priceList): Response
    {
        $priceList->load('items.product');

        return Inertia::render('Catalog/PriceLists/Form', [
            'priceList' => [
                'id'         => $priceList->id,
                'code'       => $priceList->code,
                'name'       => $priceList->name,
                'valid_from' => $priceList->valid_from?->format('Y-m-d'),
                'valid_to'   => $priceList->valid_to?->format('Y-m-d'),
                'is_default' => $priceList->is_default,
                'notes'      => $priceList->notes,
                'items'      => $priceList->items->map(fn ($i) => [
                    'product_id'   => $i->product_id,
                    'product_name' => $i->product->name,
                    'unit'         => $i->product->unit,
                    'unit_price'   => $i->unit_price,
                ]),
            ],
            'products' => Product::where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'unit', 'sell_price']),
        ]);
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:150'],
            'valid_from' => ['nullable', 'date'],
            'valid_to'   => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_default' => ['boolean'],
            'notes'      => ['nullable', 'string'],
            'items'      => ['array'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $items = $data['items'] ?? [];
        unset($data['items']);

        DB::transaction(function () use ($priceList, $data, $items) {
            $priceList->update($data);

            // Sync items: delete all, re-insert
            $priceList->items()->delete();
            foreach ($items as $item) {
                $priceList->items()->create([
                    'product_id' => $item['product_id'],
                    'unit_price' => $item['unit_price'],
                ]);
            }
        });

        return redirect()->route('catalog.price-lists.index')
            ->with('success', 'Đã cập nhật bảng giá.');
    }

    public function destroy(PriceList $priceList): RedirectResponse
    {
        $priceList->delete();

        return redirect()->route('catalog.price-lists.index')
            ->with('success', 'Đã xóa bảng giá.');
    }

    public function items(PriceList $priceList): JsonResponse
    {
        return response()->json(
            $priceList->items()
                ->with('product:id,name,sell_price')
                ->get()
                ->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'unit_price' => $i->unit_price,
                ])
        );
    }
}
