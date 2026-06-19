<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CustomerAdvanceAllocation;
use App\Models\CustomerOpeningAdvance;
use App\Models\Invoice;
use App\Services\CustomerAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerAdvanceAllocationController extends Controller
{
    public function __construct(private CustomerAdvanceService $service) {}

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'opening_advance_id' => ['required', 'exists:customer_opening_advances,id'],
            'allocated_amount'   => ['required', 'numeric', 'min:1'],
            'allocation_date'    => ['required', 'date'],
            'reason'             => ['nullable', 'string', 'max:500'],
        ]);

        $advance = CustomerOpeningAdvance::findOrFail($data['opening_advance_id']);

        try {
            $this->service->allocate(
                $advance,
                $invoice,
                (float) $data['allocated_amount'],
                $data['allocation_date'],
                $data['reason'] ?? null
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Tự động đánh dấu Paid nếu đã đủ (cash + advance)
        $invoice->refresh();
        $settled = (float) $invoice->payments()->sum('amount')
                 + (float) ($invoice->advance_allocated_amount ?? 0);
        if ($settled >= (float) $invoice->total
            && in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue])) {
            $invoice->update(['status' => InvoiceStatus::Paid]);
        }

        return back()->with('success', 'Đã đối trừ ' . number_format($data['allocated_amount']) . ' đ từ ứng trước khách hàng.');
    }

    public function destroy(Request $request, CustomerAdvanceAllocation $allocation): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $this->service->reverse($allocation, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Hoàn về Sent/Overdue nếu đang là Paid sau khi thu hồi đối trừ
        if ($allocation->invoice_id) {
            $invoice = Invoice::find($allocation->invoice_id);
            if ($invoice && $invoice->status === InvoiceStatus::Paid) {
                $newStatus = $invoice->due_date && now()->gt($invoice->due_date)
                    ? InvoiceStatus::Overdue
                    : InvoiceStatus::Sent;
                $invoice->update(['status' => $newStatus]);
            }
        }

        return back()->with('success', 'Đã thu hồi chứng từ đối trừ.');
    }
}
