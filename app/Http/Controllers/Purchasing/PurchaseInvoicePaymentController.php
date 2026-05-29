<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurchaseInvoicePaymentController extends Controller
{
    public function __construct(private PurchaseInvoiceService $service) {}

    public function store(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $amountDue = $purchaseInvoice->amountDue();
        if ($amountDue <= 0) {
            return back()->with('error', 'Hóa đơn NCC đã được thanh toán đầy đủ.');
        }

        $data = $request->validate([
            'amount'       => ['required', 'numeric', 'min:0.01', 'max:' . $amountDue],
            'payment_date' => ['required', 'date'],
            'method'       => ['required', 'in:cash,bank_transfer,other'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
        ]);

        try {
            $this->service->addPayment($purchaseInvoice, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi nhận thanh toán.');
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
