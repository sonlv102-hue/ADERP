<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PaymentScheduleStatus;
use App\Http\Controllers\Controller;
use App\Models\PurchaseContract;
use App\Models\PurchaseContractPaymentSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseContractPaymentScheduleController extends Controller
{
    public function store(Request $request, PurchaseContract $purchaseContract): RedirectResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'due_date'   => ['nullable', 'date'],
            'notes'      => ['nullable', 'string'],
        ]);

        $error = DB::transaction(function () use ($purchaseContract, $data) {
            // Lock rows to prevent concurrent over-allocation
            $totalPercent = $purchaseContract->paymentSchedules()->lockForUpdate()->sum('percentage');
            $remaining = round(100 - $totalPercent, 2);

            if ($data['percentage'] > $remaining) {
                return "Tổng % đã vượt 100%. Còn có thể thêm tối đa {$remaining}%.";
            }

            $purchaseContract->paymentSchedules()->create([
                'name'       => $data['name'],
                'percentage' => $data['percentage'],
                'amount'     => round((float) $purchaseContract->value * $data['percentage'] / 100, 0),
                'due_date'   => $data['due_date'] ?? null,
                'status'     => PaymentScheduleStatus::Pending,
                'notes'      => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            return null;
        });

        if ($error) {
            return back()->withErrors(['percentage' => $error])->withInput();
        }

        return back()->with('success', 'Đã thêm đợt thanh toán.');
    }

    public function update(Request $request, PurchaseContract $purchaseContract, PurchaseContractPaymentSchedule $schedule): RedirectResponse
    {
        abort_if($schedule->purchase_contract_id !== $purchaseContract->id, 404);
        abort_if($schedule->status === PaymentScheduleStatus::Paid, 403, 'Không sửa được đợt đã thanh toán.');

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:100'],
            'percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'due_date'   => ['nullable', 'date'],
            'notes'      => ['nullable', 'string'],
        ]);

        $error = DB::transaction(function () use ($purchaseContract, $schedule, $data) {
            $otherPercent = $purchaseContract->paymentSchedules()
                ->where('id', '!=', $schedule->id)
                ->lockForUpdate()
                ->sum('percentage');
            $remaining = round(100 - $otherPercent, 2);

            if ($data['percentage'] > $remaining) {
                return "Tổng % vượt 100%. Tối đa cho đợt này: {$remaining}%.";
            }

            $schedule->update([
                'name'       => $data['name'],
                'percentage' => $data['percentage'],
                'amount'     => round((float) $purchaseContract->value * $data['percentage'] / 100, 0),
                'due_date'   => $data['due_date'] ?? null,
                'notes'      => $data['notes'] ?? null,
            ]);

            return null;
        });

        if ($error) {
            return back()->withErrors(['percentage' => $error])->withInput();
        }

        return back()->with('success', 'Đã cập nhật đợt thanh toán.');
    }

    public function destroy(PurchaseContract $purchaseContract, PurchaseContractPaymentSchedule $schedule): RedirectResponse
    {
        abort_if($schedule->purchase_contract_id !== $purchaseContract->id, 404);
        abort_if($schedule->status === PaymentScheduleStatus::Paid, 403, 'Không xóa được đợt đã thanh toán.');

        $schedule->delete();

        return back()->with('success', 'Đã xóa đợt thanh toán.');
    }

    public function markPaid(Request $request, PurchaseContract $purchaseContract, PurchaseContractPaymentSchedule $schedule): RedirectResponse
    {
        abort_if($schedule->purchase_contract_id !== $purchaseContract->id, 404);
        abort_if($schedule->status === PaymentScheduleStatus::Paid, 403, 'Đợt này đã được thanh toán.');

        $data = $request->validate([
            'paid_date'         => ['required', 'date'],
            'payment_method'    => ['required', 'in:bank_transfer,cash,other'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);

        $schedule->update([
            'status'             => PaymentScheduleStatus::Paid,
            'paid_date'          => $data['paid_date'],
            'payment_method'     => $data['payment_method'],
            'payment_reference'  => $data['payment_reference'] ?? null,
        ]);

        return back()->with('success', 'Đã đánh dấu thanh toán.');
    }

    public function markPending(PurchaseContract $purchaseContract, PurchaseContractPaymentSchedule $schedule): RedirectResponse
    {
        abort_if($schedule->purchase_contract_id !== $purchaseContract->id, 404);
        abort_if($schedule->status !== PaymentScheduleStatus::Paid, 403, 'Chỉ hoàn tác được đợt đã thanh toán.');

        $schedule->update([
            'status'             => PaymentScheduleStatus::Pending,
            'paid_date'          => null,
            'payment_method'     => null,
            'payment_reference'  => null,
        ]);

        return back()->with('success', 'Đã hoàn tác thanh toán.');
    }
}
