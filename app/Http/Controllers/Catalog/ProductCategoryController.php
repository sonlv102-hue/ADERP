<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Catalog/ProductCategories/Index', [
            'categories' => ProductCategory::with('parent')
                ->orderBy('name')
                ->paginate(20)
                ->through(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'parent' => $c->parent ? ['id' => $c->parent->id, 'name' => $c->parent->name] : null,
                    'description' => $c->description,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/ProductCategories/Form', [
            'parents'  => ProductCategory::orderBy('name')->get(['id', 'name']),
            'accounts' => AccountCode::where('is_active', true)->where('is_detail', true)
                ->orderBy('code')->get(['code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'parent_id'            => ['nullable', 'exists:product_categories,id'],
            'description'          => ['nullable', 'string'],
            'revenue_account_code' => ['nullable', 'string', 'exists:account_codes,code'],
        ]);

        $data['slug'] = Str::slug($data['name']);

        ProductCategory::create($data);

        return redirect()->route('catalog.product-categories.index')
            ->with('success', 'Đã tạo danh mục.');
    }

    public function edit(ProductCategory $productCategory): Response
    {
        return Inertia::render('Catalog/ProductCategories/Form', [
            'category' => [
                'id'                   => $productCategory->id,
                'name'                 => $productCategory->name,
                'parent_id'            => $productCategory->parent_id,
                'description'          => $productCategory->description,
                'revenue_account_code' => $productCategory->revenue_account_code,
            ],
            'parents'  => ProductCategory::where('id', '!=', $productCategory->id)
                ->orderBy('name')->get(['id', 'name']),
            'accounts' => AccountCode::where('is_active', true)->where('is_detail', true)
                ->orderBy('code')->get(['code', 'name']),
        ]);
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'parent_id'            => ['nullable', 'exists:product_categories,id'],
            'description'          => ['nullable', 'string'],
            'revenue_account_code' => ['nullable', 'string', 'exists:account_codes,code'],
        ]);

        $data['slug'] = Str::slug($data['name']);
        $productCategory->update($data);

        return redirect()->route('catalog.product-categories.index')
            ->with('success', 'Đã cập nhật danh mục.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        $productCategory->delete();

        return redirect()->route('catalog.product-categories.index')
            ->with('success', 'Đã xóa danh mục.');
    }
}
