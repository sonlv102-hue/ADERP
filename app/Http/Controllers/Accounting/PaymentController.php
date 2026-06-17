<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'fund_id'      => ['required', Rule::exists('funds', 'id')->where('is_active', true)],
            'reference'    => ['nullable', 'string', 'max:100'],
            'notes'        => ['nullable', 'string'],
        ]);

        // Kiểm tra fund.type khớp method
        $fund = Fund::find($data['fund_id']);
        if ($data['method'] === 'cash' && $fund?->type !== 'cash') {
            return back()->withErrors(['fund_id' => 'Hình thức Tiền mặt phải chọn quỹ tiền mặt.']);
        }
        if ($data['method'] === 'bank_transfer' && $fund?->type !== 'bank') {
            return back()->withErrors(['fund_id' => 'Hình thức Chuyển khoản phải chọn tài khoản ngân hàng.']);
        }

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
