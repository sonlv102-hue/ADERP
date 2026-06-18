<?php

namespace App\Services;

use App\Models\ArApOpeningBalance;
use App\Models\Customer;
use App\Models\CustomerAdvanceAllocation;
use App\Models\CustomerOpeningAdvance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerAdvanceService
{
    public function __construct(private AccountingService $accounting) {}

    public function create(array $data): CustomerOpeningAdvance
    {
        $data['remaining_amount'] = $data['amount'];
        $data['status']           = 'open';
        $data['advance_type']     = $data['advance_type'] ?? 'opening_balance';
        $data['account_code']     = AccountingSettings::get('customer_advance_account', '131UT');
        $data['created_by']       = auth()->id();
        return CustomerOpeningAdvance::create($data);
    }

    public function cancel(CustomerOpeningAdvance $advance, string $reason): void
    {
        if ($advance->activeAllocations()->exists()) {
            throw new \RuntimeException('Không thể hủy khoản ứng trước đã có đối trừ đang hoạt động.');
        }
        $advance->update([
            'status' => 'cancelled',
            'notes'  => trim(($advance->notes ?? '') . "\nHủy: {$reason}"),
        ]);
    }

    /**
     * Đối trừ ứng trước KH vào hóa đơn bán hàng.
     * Bút toán: Dr 131UT (giảm ứng trước) / Cr 1311 (giảm phải thu).
     */
    public function allocate(
        CustomerOpeningAdvance $advance,
        Invoice $invoice,
        float $amount,
        string $allocationDate,
        ?string $reason = null
    ): CustomerAdvanceAllocation {
        if ($advance->customer_id !== $invoice->customer_id) {
            throw new \RuntimeException('Khoản ứng trước và hóa đơn phải thuộc cùng khách hàng.');
        }
        if (!$advance->isAvailable()) {
            throw new \RuntimeException('Khoản ứng trước đã dùng hết hoặc đã hủy.');
        }

        $remaining = (float) $advance->remaining_amount;
        if ($amount > $remaining + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá ứng trước còn lại (' . number_format($remaining) . ').'
            );
        }

        $invoiceDue = (float) $invoice->amount_due - (float) ($invoice->advance_allocated_amount ?? 0);
        if ($amount > $invoiceDue + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá số còn phải thu của hóa đơn (' . number_format($invoiceDue) . ').'
            );
        }

        return DB::transaction(function () use ($advance, $invoice, $amount, $allocationDate, $reason) {
            $customer         = Customer::findOrFail($advance->customer_id);
            $receivableAccount = $customer->getReceivableAccount();
            $advanceAccount   = $advance->account_code;

            // JE: Dr 131UT (giảm ứng trước KH) / Cr 1311 (giảm phải thu)
            $je = $this->accounting->post(
                description: "Đối trừ ứng trước KH #{$advance->id} vào HĐ {$invoice->code}",
                date: $allocationDate,
                lines: [
                    [
                        'account'      => $advanceAccount,
                        'debit'        => $amount,
                        'credit'       => 0,
                        'description'  => "Giảm ứng trước KH {$customer->name}",
                        'partner_type' => 'customer',
                        'partner_id'   => $advance->customer_id,
                    ],
                    [
                        'account'      => $receivableAccount,
                        'debit'        => 0,
                        'credit'       => $amount,
                        'description'  => "Đối trừ phải thu KH {$customer->name} HĐ {$invoice->code}",
                        'partner_type' => 'customer',
                        'partner_id'   => $advance->customer_id,
                    ],
                ],
                referenceType: CustomerAdvanceAllocation::class,
                referenceId: 0,
                isAuto: false,
            );

            $allocation = CustomerAdvanceAllocation::create([
                'customer_id'         => $advance->customer_id,
                'opening_advance_id'  => $advance->id,
                'invoice_id'          => $invoice->id,
                'allocation_date'     => $allocationDate,
                'allocated_amount'    => $amount,
                'status'              => 'active',
                'reason'              => $reason,
                'journal_entry_id'    => $je?->id,
                'created_by'          => auth()->id(),
            ]);

            if ($je) {
                JournalEntry::where('id', $je->id)->update(['reference_id' => $allocation->id]);
            }

            $newRemaining = round($remaining - $amount, 2);
            $advance->update([
                'remaining_amount' => $newRemaining,
                'status'           => $newRemaining <= 0 ? 'fully_applied' : 'partially_applied',
            ]);

            $newAllocated = round((float) ($invoice->advance_allocated_amount ?? 0) + $amount, 2);
            $invoice->update(['advance_allocated_amount' => $newAllocated]);

            Log::info("CustomerAdvance #{$advance->id} đối trừ " . number_format($amount) . " đ vào hóa đơn #{$invoice->id} ({$invoice->code})");

            return $allocation;
        });
    }

    /**
     * Thu hồi đối trừ — đảo JE + hoàn lại remaining.
     */
    public function reverse(CustomerAdvanceAllocation $allocation, string $reason): void
    {
        if ($allocation->isReversed()) {
            throw new \RuntimeException('Chứng từ đối trừ này đã bị thu hồi trước đó.');
        }

        DB::transaction(function () use ($allocation, $reason) {
            if ($allocation->journal_entry_id) {
                $je = JournalEntry::find($allocation->journal_entry_id);
                if ($je && $je->status === 'posted') {
                    $this->accounting->reverse($je, "Thu hồi đối trừ ứng trước KH: {$reason}");
                }
            }

            $advance = $allocation->advance;
            $amount  = (float) $allocation->allocated_amount;

            $allocation->update([
                'status'      => 'reversed',
                'reason'      => trim(($allocation->reason ?? '') . "\nThu hồi: {$reason}"),
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
            ]);

            $newRemaining = round((float) $advance->remaining_amount + $amount, 2);
            $advance->update([
                'remaining_amount' => $newRemaining,
                'status'           => $newRemaining >= (float) $advance->amount ? 'open' : 'partially_applied',
            ]);

            if ($allocation->invoice_id) {
                $invoice = $allocation->invoice;
                if ($invoice) {
                    $newAllocated = round(max(0, (float) ($invoice->advance_allocated_amount ?? 0) - $amount), 2);
                    $invoice->update(['advance_allocated_amount' => $newAllocated]);
                }
            } elseif ($allocation->ar_ap_opening_balance_id) {
                $ob = $allocation->openingBalance;
                $ob->update(['remaining_amount' => round((float) $ob->remaining_amount + $amount, 2)]);
            }
        });
    }

    /**
     * Đối trừ ứng trước KH với công nợ đầu kỳ AR.
     */
    public function allocateToOpeningBalance(
        CustomerOpeningAdvance $advance,
        ArApOpeningBalance $openingBalance,
        float $amount,
        string $allocationDate,
        ?string $reason = null
    ): CustomerAdvanceAllocation {
        if ($advance->customer_id !== $openingBalance->customer_id) {
            throw new \RuntimeException('Khoản ứng trước và công nợ đầu kỳ phải thuộc cùng khách hàng.');
        }
        if ($openingBalance->type !== 'ar') {
            throw new \RuntimeException('Chỉ đối trừ được công nợ phải thu (AR).');
        }
        if (!$advance->isAvailable()) {
            throw new \RuntimeException('Khoản ứng trước đã dùng hết hoặc đã hủy.');
        }

        $advRemaining = (float) $advance->remaining_amount;
        $obRemaining  = (float) $openingBalance->remaining_amount;

        if ($amount > $advRemaining + 0.01) {
            throw new \RuntimeException('Số đối trừ vượt quá ứng trước còn lại.');
        }
        if ($amount > $obRemaining + 0.01) {
            throw new \RuntimeException('Số đối trừ vượt quá công nợ còn lại.');
        }

        return DB::transaction(function () use ($advance, $openingBalance, $amount, $allocationDate, $reason, $advRemaining, $obRemaining) {
            $allocation = CustomerAdvanceAllocation::create([
                'customer_id'              => $advance->customer_id,
                'opening_advance_id'       => $advance->id,
                'ar_ap_opening_balance_id' => $openingBalance->id,
                'allocation_date'          => $allocationDate,
                'allocated_amount'         => $amount,
                'status'                   => 'active',
                'reason'                   => $reason,
                'created_by'               => auth()->id(),
            ]);

            $advance->update([
                'remaining_amount' => round($advRemaining - $amount, 2),
                'status'           => ($advRemaining - $amount) <= 0 ? 'fully_applied' : 'partially_applied',
            ]);
            $openingBalance->update(['remaining_amount' => round($obRemaining - $amount, 2)]);

            return $allocation;
        });
    }

    public function getAvailable(int $customerId): Collection
    {
        return CustomerOpeningAdvance::where('customer_id', $customerId)
            ->available()
            ->with('customer')
            ->orderBy('advance_date')
            ->get();
    }

    public function totalAvailable(int $customerId): float
    {
        return (float) CustomerOpeningAdvance::where('customer_id', $customerId)
            ->available()
            ->sum('remaining_amount');
    }
}
