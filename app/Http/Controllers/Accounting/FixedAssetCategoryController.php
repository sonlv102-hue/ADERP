<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FixedAssetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetCategoryController extends Controller
{
    public function index(): Response
    {
        $categories = FixedAssetCategory::withCount('fixedAssets')
            ->orderBy('code')
            ->get();

        return Inertia::render('Accounting/FixedAssets/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                       => 'required|string|max:20|unique:fixed_asset_categories,code',
            'name'                       => 'required|string|max:255',
            'asset_account_code'         => 'nullable|string|max:20',
            'depreciation_account_code'  => 'nullable|string|max:20',
            'expense_account_code'       => 'nullable|string|max:20',
            'min_useful_life_months'     => 'nullable|integer|min:1',
            'max_useful_life_months'     => 'nullable|integer|min:1',
            'legal_basis'                => 'nullable|string|max:100',
            'description'                => 'nullable|string',
        ]);

        FixedAssetCategory::create($data);

        return back()->with('success', 'Đã tạo nhóm tài sản.');
    }

    public function update(Request $request, FixedAssetCategory $fixedAssetCategory): RedirectResponse
    {
        $data = $request->validate([
            'code'                       => 'required|string|max:20|unique:fixed_asset_categories,code,' . $fixedAssetCategory->id,
            'name'                       => 'required|string|max:255',
            'asset_account_code'         => 'nullable|string|max:20',
            'depreciation_account_code'  => 'nullable|string|max:20',
            'expense_account_code'       => 'nullable|string|max:20',
            'min_useful_life_months'     => 'nullable|integer|min:1',
            'max_useful_life_months'     => 'nullable|integer|min:1',
            'legal_basis'                => 'nullable|string|max:100',
            'description'                => 'nullable|string',
        ]);

        $fixedAssetCategory->update($data);

        return back()->with('success', 'Đã cập nhật nhóm tài sản.');
    }

    public function destroy(FixedAssetCategory $fixedAssetCategory): RedirectResponse
    {
        if ($fixedAssetCategory->fixedAssets()->exists()) {
            return back()->with('error', 'Không thể xóa nhóm tài sản đang có tài sản thuộc nhóm này.');
        }

        $fixedAssetCategory->delete();

        return back()->with('success', 'Đã xóa nhóm tài sản.');
    }
}
