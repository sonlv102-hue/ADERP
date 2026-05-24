<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetController extends Controller
{
    public function index(): Response
    {
        $assets = FixedAsset::orderBy('code')->limit(500)->get()->map(fn (FixedAsset $fa) => [
            'id'                       => $fa->id,
            'code'                     => $fa->code,
            'name'                     => $fa->name,
            'category'                 => $fa->category,
            'acquisition_date'         => $fa->acquisition_date?->format('Y-m-d'),
            'acquisition_cost'         => $fa->acquisition_cost,
            'useful_life_months'       => $fa->useful_life_months,
            'accumulated_depreciation' => $fa->accumulated_depreciation,
            'net_book_value'           => $fa->net_book_value,
            'status'                   => $fa->status,
        ]);

        return Inertia::render('Admin/FixedAssets/Index', [
            'assets' => $assets,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/FixedAssets/Form', ['asset' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'                     => 'required|string|max:50|unique:fixed_assets,code',
            'name'                     => 'required|string|max:255',
            'category'                 => 'nullable|string|max:100',
            'acquisition_date'         => 'required|date',
            'acquisition_cost'         => 'required|numeric|min:0',
            'useful_life_months'       => 'required|integer|min:1',
            'depreciation_method'      => 'required|in:straight_line',
            'accumulated_depreciation' => 'nullable|numeric|min:0',
            'location'                 => 'nullable|string|max:255',
            'status'                   => 'required|in:active,disposed,fully_depreciated',
            'notes'                    => 'nullable|string',
        ]);

        FixedAsset::create($data);

        return redirect()->route('admin.fixed-assets.index')
            ->with('success', 'Tài sản cố định đã được tạo.');
    }

    public function edit(FixedAsset $fixedAsset): Response
    {
        return Inertia::render('Admin/FixedAssets/Form', [
            'asset' => [
                'id'                       => $fixedAsset->id,
                'code'                     => $fixedAsset->code,
                'name'                     => $fixedAsset->name,
                'category'                 => $fixedAsset->category,
                'acquisition_date'         => $fixedAsset->acquisition_date?->format('Y-m-d'),
                'acquisition_cost'         => $fixedAsset->acquisition_cost,
                'useful_life_months'       => $fixedAsset->useful_life_months,
                'depreciation_method'      => $fixedAsset->depreciation_method,
                'accumulated_depreciation' => $fixedAsset->accumulated_depreciation,
                'location'                 => $fixedAsset->location,
                'status'                   => $fixedAsset->status,
                'notes'                    => $fixedAsset->notes,
            ],
        ]);
    }

    public function update(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'code'                     => 'required|string|max:50|unique:fixed_assets,code,' . $fixedAsset->id,
            'name'                     => 'required|string|max:255',
            'category'                 => 'nullable|string|max:100',
            'acquisition_date'         => 'required|date',
            'acquisition_cost'         => 'required|numeric|min:0',
            'useful_life_months'       => 'required|integer|min:1',
            'depreciation_method'      => 'required|in:straight_line',
            'accumulated_depreciation' => 'nullable|numeric|min:0',
            'location'                 => 'nullable|string|max:255',
            'status'                   => 'required|in:active,disposed,fully_depreciated',
            'notes'                    => 'nullable|string',
        ]);

        $fixedAsset->update($data);

        return redirect()->route('admin.fixed-assets.index')
            ->with('success', 'Tài sản cố định đã được cập nhật.');
    }

    public function destroy(FixedAsset $fixedAsset): RedirectResponse
    {
        $fixedAsset->delete();

        return redirect()->route('admin.fixed-assets.index')
            ->with('success', 'Tài sản cố định đã được xóa.');
    }
}
