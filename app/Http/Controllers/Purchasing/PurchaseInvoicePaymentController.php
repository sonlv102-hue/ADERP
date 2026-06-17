<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use App\Models\SupplierOpeningAdvance;
use App\Services\PurchaseInvoiceService;
use App\Services\SupplierAdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseInvoicePaymentController extends Controller
{
    public function __construct(
        private PurchaseInvoiceService $service,
        private SupplierAdvanceService $advanceService,
    ) {}

    public function store(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $amountDue = $purchaseInvoice->amountDue();
        if ($amountDue <= 0) {
            return back()->with('error', 'Hóa đơn NCC đã được thanh toán đầy đủ.');
        }

        $paymentType = $request->input('payment_type', 'cash');

        $rules = ['payment_type' => ['required', 'in:cash,offset,combined']];

        // Advance allocations required for offset/combined
        if (in_array($paymentType, ['offset', 'combined'])) {
            $rules['advance_allocations']              = ['required', 'array', 'min:1'];
            $rules['advance_allocations.*.advance_id'] = ['required', 'integer', 'exists:supplier_opening_advances,id'];
            $rules['advance_allocations.*.amount']     = ['required', 'numeric', 'min:1'];
            $rules['allocation_date']                  = ['required', 'date'];
        }

        // Cash fields required for cash/combined
        if (in_array($paymentType, ['cash', 'combined'])) {
            $rules['amount']       = ['required', 'numeric', 'min:0.01', 'max:' . $amountDue];
            $rules['payment_date'] = ['required', 'date'];
            $rules['method']       = ['required', 'in:cash,bank_transfer,other'];
            $rules['fund_id']      = ['required', Rule::exists('funds', 'id')->where('is_active', true)];
            $rules['reference']    = ['nullable', 'string', 'max:100'];
            $rules['notes']        = ['nullable', 'string'];
        }

        $data = $request->validate($rules);

        // Validate total not exceeding amount due
        if (in_array($paymentType, ['offset', 'combined'])) {
            $totalOffset = collect($data['advance_allocations'])->sum('amount');
            $totalCash   = $paymentType === 'combined' ? ($data['amount'] ?? 0) : 0;
            if ($totalOffset + $totalCash > $amountDue + 0.01) {
                return back()->with('error',
                    'Tổng thanh toán (' . number_format($totalOffset + $totalCash) .
                    ' đ) vượt quá số còn phải trả (' . number_format($amountDue) . ' đ).'
                );
            }
        }

        // Fund type validation for cash/combined
        if (in_array($paymentType, ['cash', 'combined'])) {
            $fund = Fund::find($data['fund_id']);
            if ($data['method'] === 'cash' && $fund?->type !== 'cash') {
                return back()->withErrors(['fund_id' => 'Hình thức Tiền mặt phải chọn quỹ tiền mặt.']);
            }
            if ($data['method'] === 'bank_transfer' && $fund?->type !== 'bank') {
                return back()->withErrors(['fund_id' => 'Hình thức Chuyển khoản phải chọn tài khoản ngân hàng.']);
            }
        }

        try {
            DB::transaction(function () use ($data, $paymentType, $purchaseInvoice) {
                // 1. Process advance allocations (offset / combined)
                if (in_array($paymentType, ['offset', 'combined'])) {
                    foreach ($data['advance_allocations'] as $alloc) {
                        $advance = SupplierOpeningAdvance::findOrFail($alloc['advance_id']);
                        if ($advance->supplier_id !== $purchaseInvoice->supplier_id) {
                            throw new \RuntimeException('Khoản ứng trước không thuộc nhà cung cấp này.');
                        }
                        $this->advanceService->allocate(
                            $advance,
                            $purchaseInvoice,
                            (float) $alloc['amount'],
                            $data['allocation_date'],
                            'Đối trừ khoản trả trước NCC'
                        );
                    }
                }

                // 2. Process cash payment (cash / combined)
                if (in_array($paymentType, ['cash', 'combined'])) {
                    $this->service->addPayment($purchaseInvoice, [
                        'amount'       => $data['amount'],
                        'payment_date' => $data['payment_date'],
                        'method'       => $data['method'],
                        'fund_id'      => $data['fund_id'],
                        'reference'    => $data['reference'] ?? null,
                        'notes'        => $data['notes'] ?? null,
                    ]);
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = match($paymentType) {
            'offset'   => 'Đã đối trừ khoản trả trước NCC.',
            'combined' => 'Đã ghi nhận thanh toán kết hợp đối trừ + chi tiền.',
            default    => 'Đã ghi nhận thanh toán.',
        };

        return back()->with('success', $msg);
    }

    public function destroy(PurchaseInvoice $purchaseInvoice, PurchaseInvoicePayment $payment): RedirectResponse
    {
        abort_unless($payment->purchase_invoice_id === $purchaseInvoice->id, 404);

        try {
            $this->service->removePayment($purchaseInvoice, $payment);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xóa thanh toán.');
    }
}
