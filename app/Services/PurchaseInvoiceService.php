<?php

namespace App\Services;

use App\Enums\CashVoucherBusinessType;
use App\Enums\CashVoucherStatus;
use App\Enums\CashVoucherType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Enums\StockEntryStatus;
use App\Models\CashVoucher;
use App\Models\Fund;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoicePayment;
use App\Models\PurchaseOrderItem;
use App\Models\StockEntry;
use App\Services\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseInvoiceService
{
    public function __construct(
        private AccountingService $accounting,
        private CashVoucherService $cashVoucherService,
    ) {}

    private const TRANSITIONS = [
        'pending'        => ['received', 'cancelled'],
        'received'       => ['reviewing', 'cancelled'],
        'reviewing'      => ['valid', 'need_supplement', 'cancelled'],
        'valid'          => ['cancelled'],
        'need_supplement'=> ['reviewing', 'cancelled'],
        'partial_paid'   => ['cancelled'],
        'paid'           => [],
        'cancelled'      => [],
    ];

    public function transition(PurchaseInvoice $invoice, PurchaseInvoiceStatus $newStatus): void
    {
        $allowed = self::TRANSITIONS[$invoice->status->value] ?? [];
        if (!in_array($newStatus->value, $allowed)) {
            throw new \RuntimeException("Không thể chuyển sang trạng thái \"{$newStatus->label()}\".");
        }

        $invoice->update(['status' => $newStatus]);

        // Khi hóa đơn được duyệt hợp lệ: kiểm tra có cần post JE không (mua dịch vụ)
        if ($newStatus === PurchaseInvoiceStatus::Valid) {
            $this->postInvoiceEntryIfNeeded($invoice);
        }

        // Khi hủy hóa đơn: đảo JE nếu đã post (trường hợp dịch vụ)
        if ($newStatus === PurchaseInvoiceStatus::Cancelled) {
            $this->accounting->reverseOrDelete('purchase_invoice', $invoice->id, "Hủy hóa đơn {$invoice->code}");
        }
    }

    public function addPayment(PurchaseInvoice $invoice, array $data): PurchaseInvoicePayment
    {
        if (!in_array($invoice->status, [
            PurchaseInvoiceStatus::Valid,
            PurchaseInvoiceStatus::PartialPaid,
        ])) {
            throw new \RuntimeException('Chỉ có thể ghi nhận thanh toán khi hóa đơn ở trạng thái Hợp lệ hoặc TT một phần.');
        }

        $payment = DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create([
                ...$data,
                'created_by' => auth()->id(),
            ]);

            $this->recalculatePaid($invoice);

            // Tạo Phiếu CHI (PC-) + hạch toán Dr 331 / Cr quỹ
            $voucher = $this->createAndConfirmPaymentVoucher($payment, $invoice);
            $payment->update(['cash_voucher_id' => $voucher->id]);

            return $payment;
        });

        return $payment;
    }

    private function createAndConfirmPaymentVoucher(PurchaseInvoicePayment $payment, PurchaseInvoice $invoice): CashVoucher
    {
        $invoice->loadMissing('supplier');
        $voucher = CashVoucher::create([
            'code'           => CashVoucher::generateCode(CashVoucherType::Payment),
            'type'           => CashVoucherType::Payment,
            'status'         => CashVoucherStatus::Draft,
            'fund_id'        => $payment->fund_id,
            'supplier_id'    => $invoice->supplier_id,
            'partner_type'   => 'supplier',
            'amount'         => $payment->amount,
            'voucher_date'   => $payment->payment_date,
            'description'    => "Trả tiền NCC {$invoice->code}",
            'business_type'  => CashVoucherBusinessType::PaySupplier->value,
            'reference_type' => 'purchase_invoice',
            'reference_id'   => $invoice->id,
            'created_by'     => auth()->id(),
        ]);
        $this->cashVoucherService->confirm($voucher);
        return $voucher;
    }

    public function removePayment(PurchaseInvoice $invoice, PurchaseInvoicePayment $payment): void
    {
        if ($payment->isVoided()) {
            throw new \RuntimeException('Khoản thanh toán này đã bị thu hồi trước đó.');
        }

        DB::transaction(function () use ($invoice, $payment) {
            if ($payment->cash_voucher_id) {
                $voucher = CashVoucher::find($payment->cash_voucher_id);
                if ($voucher) {
                    $this->cashVoucherService->cancel($voucher);
                }
            } else {
                // Fallback: thanh toán cũ chưa có Phiếu CHI
                $this->accounting->reverseOrDelete('purchase_invoice_payment', $payment->id, "Thu hồi thanh toán {$invoice->code}");
            }
            $payment->update([
                'status'      => 'voided',
                'void_reason' => 'Xóa từng khoản',
                'voided_by'   => auth()->id(),
                'voided_at'   => now(),
            ]);
            $this->recalculatePaid($invoice);
        });
    }

    /**
     * Thu hồi toàn bộ thanh toán của một hóa đơn.
     * - Đảo JE từng khoản (hoặc xóa nếu còn draft)
     * - Đánh dấu voided (không xóa record)
     * - Reset trạng thái hóa đơn về valid
     */
    public function recallPayments(PurchaseInvoice $invoice, string $reason): int
    {
        $allowedStatuses = [
            PurchaseInvoiceStatus::Paid,
            PurchaseInvoiceStatus::PartialPaid,
        ];

        if (!in_array($invoice->status, $allowedStatuses)) {
            throw new \RuntimeException('Chỉ thu hồi thanh toán được hóa đơn đã thanh toán hoặc thanh toán một phần.');
        }

        $activePayments = $invoice->payments()->active()->get();
        if ($activePayments->isEmpty()) {
            throw new \RuntimeException('Hóa đơn này không có khoản thanh toán nào để thu hồi.');
        }

        DB::transaction(function () use ($invoice, $activePayments, $reason) {
            $userId = auth()->id();

            foreach ($activePayments as $payment) {
                if ($payment->cash_voucher_id) {
                    $voucher = CashVoucher::find($payment->cash_voucher_id);
                    if ($voucher) {
                        $this->cashVoucherService->cancel($voucher);
                    }
                } else {
                    $this->accounting->reverseOrDelete(
                        'purchase_invoice_payment',
                        $payment->id,
                        "Thu hồi thanh toán {$invoice->code}: {$reason}"
                    );
                }
                $payment->update([
                    'status'      => 'voided',
                    'void_reason' => $reason,
                    'voided_by'   => $userId,
                    'voided_at'   => now(),
                ]);
            }

            // Tính lại paid_amount và status dựa trên advance_allocated_amount còn active,
            // tránh hardcode paid_amount=0 khi hóa đơn vẫn có khoản đối trừ trả trước.
            $this->recalculatePaid($invoice->fresh());

            Log::info("PurchaseInvoice #{$invoice->id} ({$invoice->code}): recall {$activePayments->count()} payments by user {$userId}. Reason: {$reason}");
        });

        return $activePayments->count();
    }

    /**
     * Đảo JE liên quan (nếu có) trước khi xóa hóa đơn — dùng trong controller destroy().
     * An toàn gọi khi không có JE (reverseOrDelete là no-op).
     */
    public function cleanupJeForDelete(PurchaseInvoice $invoice): void
    {
        $this->accounting->reverseOrDelete(
            'purchase_invoice',
            $invoice->id,
            "Xóa hóa đơn {$invoice->code}"
        );
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    /**
     * Post JE khi hóa đơn được duyệt hợp lệ.
     *
     * Ưu tiên 1: Nếu có purchase_invoice_items → per-line JE (mỗi dòng = một debit với project_id riêng)
     * Ưu tiên 2: invoice_type rõ ràng → routing theo loại (header-level, không có items)
     * Ưu tiên 3: invoice_type = null (hóa đơn cũ) → legacy fallback
     *
     * Hàng hóa/NVL/CCDC: JE post bởi StockService khi NK xác nhận — không post ở đây.
     * TSCĐ: JE post bởi FixedAssetService khi ghi nhận TSCĐ — không post ở đây.
     * Dịch vụ/chi phí/dự án: Post Dr [debit] + Dr 1331 / Cr 3311 tại đây.
     */
    private function postInvoiceEntryIfNeeded(PurchaseInvoice $invoice): void
    {
        // Ưu tiên 1: Có invoice_items → per-line JE với project_id riêng mỗi dòng
        $invoice->loadMissing(['items', 'supplier']);
        if ($invoice->items->isNotEmpty()) {
            $this->postFromItems($invoice);
            return;
        }

        $type = $invoice->invoice_type;

        if ($type !== null) {
            // Explicit invoice_type: inventory và fixed_asset không tạo JE ở đây
            if ($type->isInventoryBacked() || $type->isFixedAssetBacked()) {
                return;
            }

            $subtotal = (float) $invoice->subtotal;
            $tax      = (float) $invoice->tax_amount;

            if ($subtotal <= 0 && $tax <= 0) return;

            $payableAccount = $invoice->supplier->getPayableAccount();

            $debitAccount = $invoice->expense_account_code ?? $type->defaultDebitAccount();
            $vatAccount   = $type->vatInputAccount();

            $lines = [];

            if ($subtotal > 0) {
                $projectId = $invoice->project_id;
                $lines[] = [
                    'account'     => $debitAccount,
                    'debit'       => (int) round($subtotal),
                    'credit'      => 0,
                    'description' => "{$type->label()} - {$invoice->code}",
                    'project_id'  => $projectId,
                ];
            }

            if ($tax > 0) {
                $lines[] = [
                    'account'     => $vatAccount,
                    'debit'       => (int) round($tax),
                    'credit'      => 0,
                    'description' => "Thuế GTGT đầu vào ({$vatAccount}) - {$invoice->code}",
                ];
            }

            $totalCredit = array_sum(array_column($lines, 'debit'));
            if ($totalCredit <= 0) return;

            $lines[] = [
                'account'      => $payableAccount,
                'debit'        => 0,
                'credit'       => $totalCredit,
                'description'  => "Phải trả NCC - {$invoice->code}",
                'partner_type' => 'supplier',
                'partner_id'   => $invoice->supplier_id,
            ];

            $this->accounting->tryPost(
                "{$type->label()} {$invoice->code}",
                Carbon::parse($invoice->invoice_date ?? now()),
                $lines,
                'purchase_invoice',
                $invoice->id,
                'ap'
            );

            return;
        }

        // Legacy fallback (invoice_type = null): dùng getServicePortion()
        $serviceInfo = $this->getServicePortion($invoice);

        if ($serviceInfo['subtotal'] <= 0 && $serviceInfo['tax'] <= 0) {
            return;
        }

        $invoice->loadMissing('supplier');
        $payableAccount = $invoice->supplier->getPayableAccount();

        $expenseAccount = $invoice->expense_account_code
            ?? AccountingSettings::get('admin_expense_account', '6422');

        $lines = [];

        if ($serviceInfo['subtotal'] > 0) {
            $lines[] = [
                'account'     => $expenseAccount,
                'debit'       => (int) round($serviceInfo['subtotal']),
                'credit'      => 0,
                'description' => "Chi phí dịch vụ ({$expenseAccount}) - {$invoice->code}",
            ];
        }

        if ($serviceInfo['tax'] > 0) {
            $lines[] = [
                'account'     => AccountingSettings::get('vat_input_account', '1331'),
                'debit'       => (int) round($serviceInfo['tax']),
                'credit'      => 0,
                'description' => "Thuế GTGT đầu vào - {$invoice->code}",
            ];
        }

        $totalCredit = array_sum(array_column($lines, 'debit'));
        if ($totalCredit <= 0) return;

        $lines[] = [
            'account'      => $payableAccount,
            'debit'        => 0,
            'credit'       => $totalCredit,
            'description'  => "Phải trả NCC (dịch vụ) - {$invoice->code}",
            'partner_type' => 'supplier',
            'partner_id'   => $invoice->supplier_id,
        ];

        $this->accounting->tryPost(
            "Hóa đơn dịch vụ {$invoice->code}",
            Carbon::parse($invoice->invoice_date ?? now()),
            $lines,
            'purchase_invoice',
            $invoice->id,
            'ap'
        );
    }

    /**
     * Post JE per-line từ purchase_invoice_items.
     * Mỗi item = một debit line với project_id riêng.
     * VAT gom lại thành một dòng Nợ 1331.
     * Cr 3311 = tổng tất cả debit.
     */
    private function postFromItems(PurchaseInvoice $invoice): void
    {
        $type       = $invoice->invoice_type;
        $vatAccount = $type ? $type->vatInputAccount() : '1331';

        // Block nếu là inventory/fixed_asset nhưng vẫn có items (không nên xảy ra)
        if ($type && ($type->isInventoryBacked() || $type->isFixedAssetBacked())) {
            return;
        }

        $payableAccount = $invoice->supplier->getPayableAccount();
        $lines          = [];
        $totalVat       = 0;

        foreach ($invoice->items as $item) {
            $amt = (int) round((float) $item->amount);
            if ($amt <= 0) continue;

            $lines[] = [
                'account'     => $item->account_code,
                'debit'       => $amt,
                'credit'      => 0,
                'description' => $item->description ?: "Chi phí {$invoice->code}",
                'project_id'  => $item->project_id,
            ];

            $totalVat += (int) round((float) $item->tax_amount);
        }

        if (empty($lines)) return;

        if ($totalVat > 0) {
            $lines[] = [
                'account'     => $vatAccount,
                'debit'       => $totalVat,
                'credit'      => 0,
                'description' => "Thuế GTGT đầu vào - {$invoice->code}",
            ];
        }

        $totalCredit = array_sum(array_column($lines, 'debit'));

        $lines[] = [
            'account'      => $payableAccount,
            'debit'        => 0,
            'credit'       => $totalCredit,
            'description'  => "Phải trả NCC - {$invoice->code}",
            'partner_type' => 'supplier',
            'partner_id'   => $invoice->supplier_id,
        ];

        $label = $type ? $type->label() : 'Chi phí';
        $this->accounting->tryPost(
            "{$label} {$invoice->code}",
            Carbon::parse($invoice->invoice_date ?? now()),
            $lines,
            'purchase_invoice',
            $invoice->id,
            'ap'
        );
    }

    /**
     * Kiểm tra đây là hóa đơn mua hàng hóa (inventory-backed) hay dịch vụ.
     *
     * Nếu invoice_type rõ ràng: trả về isInventoryBacked() trực tiếp.
     * Nếu không có invoice_type (hóa đơn cũ): dùng legacy logic.
     *   - Check 1: PO có items hàng hóa (product_id not null, line_type không phải service/fixed_asset)
     *   - Check 2: PO đã có StockEntry confirmed → hàng đã nhận, JE đã post bởi StockService
     */
    public function isGoodsPurchase(PurchaseInvoice $invoice): bool
    {
        // Explicit invoice_type overrides legacy detection
        if ($invoice->invoice_type !== null) {
            return $invoice->invoice_type->isInventoryBacked();
        }

        if (!$invoice->purchase_order_id) return false;

        // Check 1: PO items với line_type hàng hóa/NVL/CCDC
        if (PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)
            ->whereNotNull('product_id')
            ->whereNotIn('line_type', ['service', 'fixed_asset'])
            ->exists()) {
            return true;
        }

        // Check 2: đã có NK confirmed (goods received, AP already posted by StockService)
        return StockEntry::where('purchase_order_id', $invoice->purchase_order_id)
            ->where('status', StockEntryStatus::Confirmed)
            ->exists();
    }

    /**
     * Tính phần dịch vụ/chi phí của hóa đơn.
     * - Nếu isGoodsPurchase() → không có phần dịch vụ (StockService xử lý toàn bộ)
     * - Nếu PO có mixed items (goods + service) → tỷ lệ theo giá trị service items
     * - Nếu không có PO hoặc PO chỉ có service items → toàn bộ là dịch vụ
     */
    private function getServicePortion(PurchaseInvoice $invoice): array
    {
        // Nếu toàn bộ là hàng hóa (checked bởi isGoodsPurchase) → không có phần dịch vụ
        if ($this->isGoodsPurchase($invoice)) {
            return ['subtotal' => 0, 'tax' => 0];
        }

        if (!$invoice->purchase_order_id) {
            // Không có PO → toàn bộ là dịch vụ
            return [
                'subtotal' => (float) $invoice->subtotal,
                'tax'      => (float) $invoice->tax_amount,
            ];
        }

        $allItems     = PurchaseOrderItem::where('purchase_order_id', $invoice->purchase_order_id)->get();
        $serviceItems = $allItems->whereIn('line_type', ['service']);

        if ($allItems->isEmpty()) {
            // PO không có items → treat as service (e.g., service PO without product lines)
            return [
                'subtotal' => (float) $invoice->subtotal,
                'tax'      => (float) $invoice->tax_amount,
            ];
        }

        if ($serviceItems->isEmpty()) {
            // Không có service item nào → toàn bộ là hàng hóa (không qua check 1 vì line_type mặc định goods)
            return ['subtotal' => 0, 'tax' => 0];
        }

        if ($allItems->count() === $serviceItems->count()) {
            // Tất cả items đều là service
            return [
                'subtotal' => (float) $invoice->subtotal,
                'tax'      => (float) $invoice->tax_amount,
            ];
        }

        // Mixed: tính tỷ lệ theo unit_price * quantity
        $totalValue   = $allItems->sum(fn ($i) => (float) $i->unit_price * (float) $i->quantity);
        $serviceValue = $serviceItems->sum(fn ($i) => (float) $i->unit_price * (float) $i->quantity);

        if ($totalValue <= 0) return ['subtotal' => 0, 'tax' => 0];

        $ratio = $serviceValue / $totalValue;

        return [
            'subtotal' => (float) $invoice->subtotal * $ratio,
            'tax'      => (float) $invoice->tax_amount * $ratio,
        ];
    }

    private function recalculatePaid(PurchaseInvoice $invoice): void
    {
        $invoice->refresh();
        $paid      = (float) $invoice->payments()->active()->sum('amount');
        $total     = (float) $invoice->total;
        $allocated = (float) $invoice->advance_allocated_amount;
        $totalPaid = $paid + $allocated;

        $status = match(true) {
            $invoice->status === PurchaseInvoiceStatus::Cancelled => $invoice->status,
            $totalPaid <= 0                                         => PurchaseInvoiceStatus::Valid,
            $totalPaid >= $total                                    => PurchaseInvoiceStatus::Paid,
            default                                                 => PurchaseInvoiceStatus::PartialPaid,
        };

        $invoice->update([
            'paid_amount' => $paid,
            'status'      => $status,
        ]);
    }

}

