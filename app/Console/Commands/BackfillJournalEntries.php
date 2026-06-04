<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\StockEntry;
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
