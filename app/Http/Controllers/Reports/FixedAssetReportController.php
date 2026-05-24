<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\FixedAssetExport;
use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FixedAssetReportController extends Controller
{
    public function index(Request $request): Response
    {
        $status   = $request->input('status', '');
        $category = $request->input('category', '');

        $query = FixedAsset::query();

        if ($status) {
            $query->where('status', $status);
        }
        if ($category) {
            $query->where('category', $category);
        }

        $assets = $query->orderBy('acquisition_date')->get()
            ->map(function (FixedAsset $fa) {
                return [
                    'id'                       => $fa->id,
                    'code'                     => $fa->code,
                    'name'                     => $fa->name,
                    'category'                 => $fa->category,
                    'acquisition_date'         => $fa->acquisition_date?->format('Y-m-d'),
                    'acquisition_cost'         => $fa->acquisition_cost,
                    'useful_life_months'       => $fa->useful_life_months,
                    'depreciation_method'      => $fa->depreciation_method,
                    'monthly_depreciation'     => $fa->monthly_depreciation,
                    'annual_depreciation'      => $fa->annual_depreciation,
                    'depreciation_rate'        => $fa->depreciation_rate,
                    'accumulated_depreciation' => $fa->accumulated_depreciation,
                    'net_book_value'           => $fa->net_book_value,
                    'location'                 => $fa->location,
                    'status'                   => $fa->status,
                    'notes'                    => $fa->notes,
                ];
            });

        $categories = FixedAsset::whereNull('deleted_at')
            ->distinct()->pluck('category')->filter()->values();

        $summary = [
            'total_cost'             => $assets->sum('acquisition_cost'),
            'total_accumulated_dep'  => $assets->sum('accumulated_depreciation'),
            'total_net_book_value'   => $assets->sum('net_book_value'),
            'total_annual_dep'       => $assets->sum('annual_depreciation'),
            'count'                  => $assets->count(),
            'count_active'           => $assets->where('status', 'active')->count(),
        ];

        return Inertia::render('Reports/FixedAssets/Index', [
            'assets'     => $assets->values(),
            'categories' => $categories,
            'summary'    => $summary,
            'filters'    => ['status' => $status, 'category' => $category],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return Excel::download(
            new FixedAssetExport($request->all()),
            'fixed-assets-' . now()->toDateString() . '.xlsx'
        );
    }
}
