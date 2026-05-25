<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Services\FixedAssetService;
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
            'last_depreciation_period' => $fa->last_depreciation_period,
            'status'                   => $fa->status,
        ]);

        return Inertia::render('Admin/FixedAssets/Index', [
            'assets' => $assets,
        ]);
    }

    public function show(FixedAsset $fixedAsset, FixedAssetService $service): Response
    {
        $schedule = $service->getSchedule($fixedAsset);

        return Inertia::render('Admin/FixedAssets/Show', [
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
                'last_depreciation_period' => $fixedAsset->last_depreciation_period,
                'net_book_value'           => $fixedAsset->net_book_value,
                'monthly_depreciation'     => $fixedAsset->monthly_depreciation,
                'location'                 => $fixedAsset->location,
                'status'                   => $fixedAsset->status,
                'notes'                    => $fixedAsset->notes,
            ],
            'schedule' => $schedule,
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

    public function depreciate(Request $request, FixedAssetService $service): RedirectResponse
    {
        $period = $request->input('period', now()->format('Y-m'));

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return back()->with('error', 'Kỳ khấu hao không hợp lệ (định dạng YYYY-MM).');
        }

        $result = $service->runMonthlyDepreciation($period);

        $msg = "Kỳ {$period}: {$result['processed']} tài sản đã khấu hao, {$result['skipped']} bỏ qua.";
        if (!empty($result['errors'])) {
            $msg .= ' Lỗi: ' . implode('; ', $result['errors']);
            return back()->with('error', $msg);
        }

        return back()->with('success', $msg);
    }
}
