<?php

namespace App\Services;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierAdvanceService
{
    public function create(array $data): SupplierOpeningAdvance
    {
        $data['remaining_amount'] = $data['amount'];
        $data['status']           = 'open';
        $data['advance_type']     = $data['advance_type'] ?? 'opening_balance';
        $data['created_by']       = auth()->id();
        return SupplierOpeningAdvance::create($data);
    }

    /**
     * Tạo khoản trả trước trong kỳ (không phải số dư đầu kỳ).
     * Khoản này có thể đối trừ vào hóa đơn sau.
     */
    public function createPrepayment(
        int $supplierId,
        float $amount,
        string $date,
        ?string $reference = null,
        ?string $notes = null,
        string $sourceType = 'manual',
        ?int $sourceId = null
    ): SupplierOpeningAdvance {
        return SupplierOpeningAdvance::create([
            'supplier_id'      => $supplierId,
            'advance_type'     => 'prepayment',
            'source_type'      => $sourceType,
            'source_id'        => $sourceId,
            'fiscal_year'      => \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql' ? null : (int) date('Y'),
            'opening_date'     => $date,
            'account_code'     => '3311',
            'amount'           => $amount,
            'remaining_amount' => $amount,
            'currency'         => 'VND',
            'reference_no'     => $reference,
            'notes'            => $notes,
            'status'           => 'open',
            'created_by'       => auth()->id(),
        ]);
    }

    public function update(SupplierOpeningAdvance $advance, array $data): void
    {
        if ($advance->activeAllocations()->exists()) {
            throw new \RuntimeException('Không thể sửa khoản ứng trước đã có đối trừ đang hoạt động.');
        }
        if (isset($data['amount'])) {
            $data['remaining_amount'] = $data['amount'];
            $data['status']           = 'open';
        }
        $advance->update($data);
    }

    public function cancel(SupplierOpeningAdvance $advance, string $reason): void
    {
        if ($advance->activeAllocations()->exists()) {
            throw new \RuntimeException('Không thể hủy khoản ứng trước đã có đối trừ đang hoạt động. Thu hồi các đối trừ trước.');
        }
        $advance->update([
            'status' => 'cancelled',
            'notes'  => trim(($advance->notes ?? '') . "\nHủy: {$reason}"),
        ]);
    }

    /**
     * Tạo chứng từ đối trừ ứng trước đầu kỳ với hóa đơn mua hàng.
     * Không tạo bút toán sổ cái — chỉ allocation record.
     */
    public function allocate(
        SupplierOpeningAdvance $advance,
        PurchaseInvoice $invoice,
        float $amount,
        string $allocationDate,
        ?string $reason = null
    ): SupplierAdvanceAllocation {
        if ($advance->supplier_id !== $invoice->supplier_id) {
            throw new \RuntimeException('Khoản ứng trước và hóa đơn phải thuộc cùng nhà cung cấp.');
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

        $invoiceDue = (float) $invoice->total
            - (float) $invoice->paid_amount
            - (float) $invoice->advance_allocated_amount;

        if ($amount > $invoiceDue + 0.01) {
            throw new \RuntimeException(
                'Số đối trừ (' . number_format($amount) . ') vượt quá số còn phải trả của hóa đơn (' . number_format($invoiceDue) . ').'
            );
        }

        if (!in_array($invoice->status, [PurchaseInvoiceStatus::Valid, PurchaseInvoiceStatus::PartialPaid])) {
            throw new \RuntimeException('Chỉ đối trừ được hóa đơn ở trạng thái Hợp lệ hoặc Thanh toán một phần.');
        }

        return DB::transaction(function () use ($advance, $invoice, $amount, $allocationDate, $reason) {
            $allocation = SupplierAdvanceAllocation::create([
                'supplier_id'         => $advance->supplier_id,
                'opening_advance_id'  => $advance->id,
                'purchase_invoice_id' => $invoice->id,
                'allocation_date'     => $allocationDate,
                'allocated_amount'    => $amount,
                'status'              => 'active',
                'reason'              => $reason,
                'created_by'          => auth()->id(),
            ]);

            $newRemaining = round((float) $advance->remaining_amount - $amount, 2);
            $advance->update([
                'remaining_amount' => $newRemaining,
                'status'           => $newRemaining <= 0 ? 'fully_applied' : 'partially_applied',
            ]);

            $newAllocated = round((float) $invoice->advance_allocated_amount + $amount, 2);
            $invoice->update(['advance_allocated_amount' => $newAllocated]);
            $this->recalculateInvoiceStatus($invoice->fresh());

            Log::info("SupplierAdvance #{$advance->id} đối trừ " . number_format($amount) . " đ vào hóa đơn #{$invoice->id} ({$invoice->code})");

            return $allocation;
        });
    }

    /**
     * Thu hồi chứng từ đối trừ — hoàn lại remaining cho ứng trước và giảm advance_allocated_amount.
     */
    public function reverse(SupplierAdvanceAllocation $allocation, string $reason): void
    {
        if ($allocation->isReversed()) {
            throw new \RuntimeException('Chứng từ đối trừ này đã bị thu hồi trước đó.');
        }

        DB::transaction(function () use ($allocation, $reason) {
            $advance = $allocation->advance;
            $invoice = $allocation->invoice;
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

            $newAllocated = round(max(0, (float) $invoice->advance_allocated_amount - $amount), 2);
            $invoice->update(['advance_allocated_amount' => $newAllocated]);
            $this->recalculateInvoiceStatus($invoice->fresh());
        });
    }

    /**
     * Danh sách ứng trước còn dư của một NCC.
     */
    public function getAvailable(int $supplierId): Collection
    {
        return SupplierOpeningAdvance::where('supplier_id', $supplierId)
            ->available()
            ->with('supplier')
            ->orderBy('opening_date')
            ->get();
    }

    /**
     * Tổng ứng trước còn lại của một NCC.
     */
    public function totalAvailable(int $supplierId): float
    {
        return (float) SupplierOpeningAdvance::where('supplier_id', $supplierId)
            ->available()
            ->sum('remaining_amount');
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    private function recalculateInvoiceStatus(PurchaseInvoice $invoice): void
    {
        $total     = (float) $invoice->total;
        $paid      = (float) $invoice->paid_amount;
        $allocated = (float) $invoice->advance_allocated_amount;
        $totalPaid = $paid + $allocated;

        $status = match(true) {
            $invoice->status === PurchaseInvoiceStatus::Cancelled => $invoice->status,
            $totalPaid <= 0                                         => PurchaseInvoiceStatus::Valid,
            $totalPaid >= $total                                    => PurchaseInvoiceStatus::Paid,
            default                                                 => PurchaseInvoiceStatus::PartialPaid,
        };

        $invoice->update(['status' => $status]);
    }
}
