<?php

namespace App\Http\Controllers\Catalog;

use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\ProductImport;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Catalog/Products/Index', [
            'products' => Product::with('category')
                ->orderBy('code')
                ->paginate(20)
                ->through(fn ($p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'unit' => $p->unit,
                    'sell_price' => $p->sell_price,
                    'has_serial' => $p->has_serial,
                    'is_active' => $p->is_active,
                    'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
                ]),
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Product $product): Response
    {
        $product->load('category');

        $stock = \App\Models\StockMovement::where('product_id', $product->id)->sum('quantity');

        return Inertia::render('Catalog/Products/Show', [
            'product' => [
                'id'              => $product->id,
                'code'            => $product->code,
                'name'            => $product->name,
                'unit'            => $product->unit,
                'category'        => $product->category?->name,
                'has_serial'      => $product->has_serial,
                'is_active'       => $product->is_active,
                'warranty_months' => $product->warranty_months,
                'min_stock'       => $product->min_stock,
                'description'     => $product->description,
                'cost_price'      => $product->cost_price,
                'business_cost'   => $product->business_cost,
                'vat_percent'     => $product->vat_percent,
                'total_cost'      => $product->total_cost,
                'sell_price'      => $product->sell_price,
                'stock'           => (int) $stock,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/Products/Form', [
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'nextCode' => Product::generateCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'unique:products,code'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:product_categories,id'],
            'unit' => ['required', 'string', 'max:50'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'business_cost' => ['numeric', 'min:0'],
            'vat_percent' => ['numeric', 'min:0', 'max:100'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'has_serial' => ['boolean'],
            'warranty_months' => ['integer', 'min:0'],
            'min_stock' => ['integer', 'min:0'],
            'description' => ['nullable', 'string'],
        ]);

        $data['total_cost'] = $this->calcTotalCost($data);

        Product::create($data);

        return redirect()->route('catalog.products.index')
            ->with('success', 'Đã tạo sản phẩm.');
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('Catalog/Products/Form', [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'category_id' => $product->category_id,
                'unit' => $product->unit,
                'cost_price' => $product->cost_price,
                'business_cost' => $product->business_cost,
                'vat_percent' => $product->vat_percent,
                'total_cost' => $product->total_cost,
                'sell_price' => $product->sell_price,
                'has_serial' => $product->has_serial,
                'warranty_months' => $product->warranty_months,
                'min_stock' => $product->min_stock,
                'description' => $product->description,
                'is_active' => $product->is_active,
            ],
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'unique:products,code,' . $product->id],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:product_categories,id'],
            'unit' => ['required', 'string', 'max:50'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'business_cost' => ['numeric', 'min:0'],
            'vat_percent' => ['numeric', 'min:0', 'max:100'],
            'sell_price' => ['required', 'numeric', 'min:0'],
            'has_serial' => ['boolean'],
            'warranty_months' => ['integer', 'min:0'],
            'min_stock' => ['integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $data['total_cost'] = $this->calcTotalCost($data);

        $product->update($data);

        return redirect()->route('catalog.products.index')
            ->with('success', 'Đã cập nhật sản phẩm.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('catalog.products.index')
            ->with('success', 'Đã xóa sản phẩm.');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $import = new ProductImport();
        Excel::import($import, $request->file('file'));

        if ($import->errors) {
            return back()->with('warning', 'Nhập ' . $import->imported . ' sản phẩm. Lỗi: ' . implode('; ', array_slice($import->errors, 0, 5)));
        }

        return back()->with('success', "Đã nhập {$import->imported} sản phẩm thành công.");
    }

    public function importTemplate()
    {
        $headers = ['name', 'sku', 'category', 'unit', 'unit_price', 'cost_price', 'has_serial', 'description'];
        return Excel::download(new TemplateExport($headers, 'Products'), 'product-template.xlsx');
    }

    private function calcTotalCost(array $data): float
    {
        // cost_price đã gồm VAT — giá vốn = giá nhập + chi phí KD nội bộ
        $costPrice    = (float) ($data['cost_price'] ?? 0);
        $businessCost = (float) ($data['business_cost'] ?? 0);

        return $costPrice + $businessCost;
    }
}
