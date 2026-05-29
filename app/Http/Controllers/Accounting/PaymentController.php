<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private InvoiceService $service) {}

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $amountDue = $invoice->amountDue();
        if ($amountDue <= 0) {
            return back()->with('error', 'Hóa đơn đã được thanh toán đầy đủ.');
        }

        $data = $request->validate([
            'amount'       => ['required', 'numeric', 'min:0.01', 'max:' . $amountDue],
            'payment_date' => ['required', 'date'],
            'method'       => ['required', 'string', 'in:cash,bank_transfer,other'],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
        ]);

        try {
            $this->service->addPayment($invoice, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi nhận thanh toán.');
    }

    public function destroy(Invoice $invoice, Payment $payment): RedirectResponse
    {
        $this->authorize('accounting.manage');

        if ($payment->invoice_id !== $invoice->id) {
            abort(404);
        }

        try {
            $this->service->removePayment($invoice, $payment);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã xóa thanh toán.');
    }
}
