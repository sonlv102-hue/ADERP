<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StockEntry;
use App\Models\StockExit;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillJournalEntries extends Command
{
    protected $signature   = 'accounting:backfill';
    protected $description = 'Tạo lại bút toán cho các chứng từ đã tồn tại (chạy 1 lần sau khi xóa bút toán cũ)';

    public function __construct(private AccountingService $accounting) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('=== Backfill journal entries ===');

        $this->backfillInvoices();
        $this->backfillStockEntries();
        $this->backfillPayments();
        $this->backfillStockExits();

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function backfillInvoices(): void
    {
        $invoices = Invoice::whereIn('status', ['sent', 'overdue', 'paid'])->get();
        $this->info("Invoices: {$invoices->count()} cần tạo bút toán");

        foreach ($invoices as $inv) {
            $inv->load('customer', 'order');

            // Kiểm tra đã có bút toán chưa
            $exists = \App\Models\JournalEntry::where('reference_type', 'invoice')
                ->where('reference_id', $inv->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip {$inv->code}: đã có bút toán");
                continue;
            }

            try {
                DB::transaction(function () use ($inv) {
                    $subtotal = (float) $inv->subtotal;
                    $tax      = (float) $inv->tax_amount;
                    $total    = (float) $inv->total;

                    $lines = [];

                    // Nợ TK 131 (công nợ phải thu)
                    $lines[] = ['account' => '131', 'debit' => $total, 'credit' => 0, 'description' => $inv->customer?->name ?? ''];

                    // Có TK 5111 (doanh thu bán hàng)
                    if ($subtotal > 0) {
                        $lines[] = ['account' => '5111', 'debit' => 0, 'credit' => $subtotal, 'description' => $inv->code];
                    }

                    // Có TK 33311 (thuế GTGT đầu ra)
                    if ($tax > 0) {
                        $lines[] = ['account' => '33311', 'debit' => 0, 'credit' => $tax, 'description' => $inv->code];
                    }

                    $this->accounting->post(
                        description: "Ghi nhận doanh thu {$inv->code}",
                        date: Carbon::parse($inv->issue_date),
                        lines: $lines,
                        referenceType: 'invoice',
                        referenceId: $inv->id,
                        isAuto: true,
                    );
                });

                $this->info("  OK {$inv->code}");
            } catch (\Throwable $e) {
                $this->warn("  Lỗi {$inv->code}: " . $e->getMessage());
            }
        }
    }

    private function backfillPayments(): void
    {
        $payments = Payment::with('invoice')->get();
        $this->info("Payments: {$payments->count()} cần tạo bút toán");

        foreach ($payments as $payment) {
            $invoice = $payment->invoice;
            if (! $invoice) {
                $this->line("  Skip payment #{$payment->id}: không có invoice");
                continue;
            }

            $exists = \App\Models\JournalEntry::where('reference_type', 'payment')
                ->where('reference_id', $payment->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip payment #{$payment->id}: đã có bút toán");
                continue;
            }

            try {
                $amount = (float) $payment->amount;
                if ($amount <= 0) continue;

                $method = $payment->method instanceof \App\Enums\PaymentMethod
                    ? $payment->method->value
                    : ($payment->method ?? 'cash');
                $cashAccount = match($method) {
                    'bank_transfer', 'bank' => '112',
                    default => '111',
                };

                $this->accounting->post(
                    description: "Thu tiền {$invoice->code}",
                    date: Carbon::parse($payment->payment_date),
                    lines: [
                        ['account' => $cashAccount, 'debit' => (int) $amount, 'credit' => 0,
                         'description' => "Thu tiền - {$invoice->code}"],
                        ['account' => '131', 'debit' => 0, 'credit' => (int) $amount,
                         'description' => "Xóa công nợ KH - {$invoice->code}"],
                    ],
                    referenceType: 'payment',
                    referenceId: $payment->id,
                    isAuto: true,
                );

                $this->info("  OK payment #{$payment->id} ({$invoice->code})");
            } catch (\Throwable $e) {
                $this->warn("  Lỗi payment #{$payment->id}: " . $e->getMessage());
            }
        }
    }

    private function backfillStockExits(): void
    {
        $exits = StockExit::with('items.product')->where('status', 'confirmed')->get();
        $this->info("StockExits: {$exits->count()} cần tạo bút toán");

        foreach ($exits as $exit) {
            $exists = \App\Models\JournalEntry::where('reference_type', 'stock_exit')
                ->where('reference_id', $exit->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip {$exit->code}: đã có bút toán");
                continue;
            }

            try {
                $isProject = $exit->item_usage_type === 'project';
                $debitAccount = $isProject ? '154' : '632';

                $totalCogs = $exit->items->sum(function ($item) {
                    $vatRate     = (float) ($item->product?->vat_percent ?? 10);
                    $costInclTax = (float) ($item->product?->cost_price ?? 0);
                    $divisor     = $vatRate > 0 ? (1 + $vatRate / 100) : 1;
                    return (int) round(($costInclTax / $divisor) * $item->quantity);
                });

                if ($totalCogs <= 0) continue;

                $this->accounting->post(
                    description: "Giá vốn hàng bán {$exit->code}",
                    date: Carbon::parse($exit->exit_date),
                    lines: [
                        ['account' => $debitAccount, 'debit' => $totalCogs, 'credit' => 0,
                         'description' => "GVHB - {$exit->code}"],
                        ['account' => '156', 'debit' => 0, 'credit' => $totalCogs,
                         'description' => "Xuất kho - {$exit->code}"],
                    ],
                    referenceType: 'stock_exit',
                    referenceId: $exit->id,
                    isAuto: true,
                );

                $this->info("  OK {$exit->code}");
            } catch (\Throwable $e) {
                $this->warn("  Lỗi {$exit->code}: " . $e->getMessage());
            }
        }
    }

    private function backfillStockEntries(): void
    {
        $entries = StockEntry::with('items.product')->where('status', 'confirmed')->get();
        $this->info("StockEntries: {$entries->count()} cần tạo bút toán");

        foreach ($entries as $entry) {
            $exists = \App\Models\JournalEntry::where('reference_type', 'stock_entry')
                ->where('reference_id', $entry->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip {$entry->code}: đã có bút toán");
                continue;
            }

            try {
                DB::transaction(function () use ($entry) {
                    $total = $entry->items->sum(fn ($i) => (float) $i->subtotal);
                    if ($total <= 0) return;

                    $this->accounting->post(
                        description: "Nhập kho hàng hóa {$entry->code}",
                        date: Carbon::parse($entry->created_at),
                        lines: [
                            ['account' => '156', 'debit' => $total, 'credit' => 0, 'description' => $entry->code],
                            ['account' => '331', 'debit' => 0, 'credit' => $total, 'description' => $entry->code],
                        ],
                        referenceType: 'stock_entry',
                        referenceId: $entry->id,
                        isAuto: true,
                    );
                });

                $this->info("  OK {$entry->code}");
            } catch (\Throwable $e) {
                $this->warn("  Lỗi {$entry->code}: " . $e->getMessage());
            }
        }
    }
}
