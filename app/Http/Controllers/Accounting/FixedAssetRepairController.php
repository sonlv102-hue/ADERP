<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\FixedAssetRepair;
use App\Services\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetRepairController extends Controller
{
    public function __construct(protected FixedAssetService $service) {}

    public function create(FixedAsset $fixedAsset): Response
    {
        return Inertia::render('Accounting/FixedAssets/Repairs/Form', [
            'asset' => ['id' => $fixedAsset->id, 'code' => $fixedAsset->code, 'name' => $fixedAsset->name],
            'repair' => null,
        ]);
    }

    public function store(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $data = $request->validate([
            'repair_type'          => 'required|in:regular,major_repair,upgrade',
            'repair_date'          => 'required|date',
            'description'          => 'required|string|max:500',
            'amount'               => 'required|numeric|min:0',
            'vat_amount'           => 'nullable|numeric|min:0',
            'supplier_id'          => 'nullable|exists:suppliers,id',
            'purchase_invoice_id'  => 'nullable|exists:purchase_invoices,id',
            'accounting_treatment' => 'required|in:expense_now,prepaid_allocation,increase_original_cost',
            'allocation_months'    => 'nullable|integer|min:1',
            'notes'                => 'nullable|string',
        ]);

        $createJournal = $request->boolean('create_journal', true);

        try {
            $repair = $this->service->createRepair($fixedAsset, $data, $createJournal);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('accounting.fixed-assets.show', $fixedAsset)
            ->with('success', 'Đã ghi nhận sửa chữa/nâng cấp.');
    }

    public function destroy(FixedAsset $fixedAsset, FixedAssetRepair $repair): RedirectResponse
    {
        if ($repair->status === 'posted') {
            return back()->with('error', 'Không thể xóa sửa chữa đã ghi sổ.');
        }

        $repair->delete();

        return back()->with('success', 'Đã xóa bản ghi sửa chữa.');
    }
}
