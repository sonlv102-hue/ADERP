<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\Request;

class SupplierAdvanceAllocationController extends Controller
{
    public function __construct(private SupplierAdvanceService $service) {}

    public function store(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->authorize('purchasing.approve');

        $data = $request->validate([
            'opening_advance_id' => ['required', 'exists:supplier_opening_advances,id'],
            'allocated_amount'   => ['required', 'numeric', 'min:1'],
            'allocation_date'    => ['required', 'date'],
            'reason'             => ['nullable', 'string', 'max:500'],
        ]);

        $advance = SupplierOpeningAdvance::findOrFail($data['opening_advance_id']);

        $this->service->allocate(
            $advance,
            $purchaseInvoice,
            (float) $data['allocated_amount'],
            $data['allocation_date'],
            $data['reason'] ?? null
        );

        return back()->with('success', 'Đã đối trừ ' . number_format($data['allocated_amount']) . ' đ từ ứng trước đầu kỳ.');
    }

    public function destroy(Request $request, SupplierAdvanceAllocation $allocation)
    {
        $this->authorize('purchasing.approve');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reverse($allocation, $data['reason']);

        return back()->with('success', 'Đã thu hồi chứng từ đối trừ.');
    }
}
