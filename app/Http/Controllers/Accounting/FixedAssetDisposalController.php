<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Services\FixedAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetDisposalController extends Controller
{
    public function __construct(protected FixedAssetService $service) {}

    public function create(FixedAsset $fixedAsset): Response
    {
        return Inertia::render('Accounting/FixedAssets/Disposals/Form', [
            'asset' => [
                'id'                       => $fixedAsset->id,
                'code'                     => $fixedAsset->code,
                'name'                     => $fixedAsset->name,
                'acquisition_cost'         => (float) $fixedAsset->acquisition_cost,
                'accumulated_depreciation' => (float) $fixedAsset->accumulated_depreciation,
                'net_book_value'           => $fixedAsset->net_book_value,
                'original_cost_account_code' => $fixedAsset->original_cost_account_code,
                'accumulated_dep_account_code' => $fixedAsset->accumulated_dep_account_code,
            ],
        ]);
    }

    public function store(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        if (in_array($fixedAsset->status->value, ['disposed', 'written_off'])) {
            return back()->with('error', 'Tài sản này đã được thanh lý/xóa sổ.');
        }

        $data = $request->validate([
            'disposal_type'       => 'required|in:liquidation,sale,damage,other',
            'disposal_date'       => 'required|date',
            'selling_price'       => 'nullable|numeric|min:0',
            'selling_vat_amount'  => 'nullable|numeric|min:0',
            'disposal_cost'       => 'nullable|numeric|min:0',
            'disposal_vat_amount' => 'nullable|numeric|min:0',
            'buyer_name'          => 'nullable|string|max:255',
            'disposal_account_code' => 'nullable|string|max:20',
            'income_account_code'   => 'nullable|string|max:20',
            'notes'               => 'nullable|string',
        ]);

        $createJournal = $request->boolean('create_journal', true);

        try {
            $disposal = $this->service->dispose($fixedAsset, $data, $createJournal);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('accounting.fixed-assets.show', $fixedAsset)
            ->with('success', 'Đã ghi nhận thanh lý TSCĐ. Kiểm tra và ghi sổ bút toán.');
    }

    public function destroy(FixedAsset $fixedAsset, FixedAssetDisposal $disposal): RedirectResponse
    {
        if ($disposal->status === 'posted') {
            return back()->with('error', 'Không thể xóa thanh lý đã ghi sổ.');
        }

        // Rollback asset status
        $fixedAsset->update(['status' => \App\Enums\FixedAssetStatus::Active->value]);
        $disposal->delete();

        return back()->with('success', 'Đã hủy thanh lý.');
    }
}
