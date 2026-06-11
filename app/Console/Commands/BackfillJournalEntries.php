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
    protected $signature   = 'accounting:backfill
                                {--dry-run : Hiển thị kế hoạch bút toán mà không ghi vào DB}
                                {--force  : Chạy thật (bắt buộc khai báo rõ để tránh chạy nhầm)}';
    protected $description = 'Tạo bút toán cho các chứng từ chưa có (dùng --dry-run trước, --force để chạy thật)';

    private bool $isDryRun = false;
    private array $dryRunSummary = [];

    public function __construct(private AccountingService $accounting) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->isDryRun = $this->option('dry-run');
        $hasForce       = $this->option('force');

        if (!$this->isDryRun && !$hasForce) {
            $this->error('Phải khai báo --dry-run hoặc --force. Chạy --dry-run trước để kiểm tra kết quả.');
            return self::FAILURE;
        }

        if ($this->isDryRun) {
            $this->warn('=== DRY-RUN MODE — không ghi vào DB ===');
        } else {
            $this->warn('=== LIVE RUN — sẽ ghi vào DB ===');
        }

        $this->backfillInvoices();
        $this->backfillStockEntries();
        $this->backfillPayments();
        $this->backfillStockExits();

        if ($this->isDryRun) {
            $this->printDryRunSummary();
        } else {
            $this->info('Done.');
        }

        return self::SUCCESS;
    }

    // ─── Invoices ─────────────────────────────────────────────────────────────

    private function backfillInvoices(): void
    {
        $invoices = Invoice::whereIn('status', ['sent', 'overdue', 'paid'])
            ->with('customer')
            ->get();

        $this->info("Invoices: {$invoices->count()} cần kiểm tra");

        foreach ($invoices as $inv) {
            $exists = \App\Models\JournalEntry::where('reference_type', 'invoice')
                ->where('reference_id', $inv->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip {$inv->code}: đã có bút toán");
                continue;
            }

            $subtotal = (float) $inv->subtotal;
            $tax      = (float) $inv->tax_amount;
            $total    = (float) $inv->total;

            if ($total <= 0) {
                $this->warn("  Skip {$inv->code}: total = 0");
                continue;
            }

            // Build revenue lines dùng M1 mapping (giống InvoiceService::buildRevenueLines)
            [$revenueLines, $needsReview] = $this->buildRevenueLines($inv, (int) round($subtotal));

            // Assemble full JE lines
            $lines = [
                ['account' => '131', 'debit' => (int) round($total), 'credit' => 0,
                 'description' => $inv->customer?->name ?? $inv->code],
            ];
            foreach ($revenueLines as $rl) {
                $lines[] = $rl;
            }
            if ($tax > 0) {
                $lines[] = ['account' => '33311', 'debit' => 0, 'credit' => (int) round($tax),
                            'description' => "Thuế GTGT đầu ra - {$inv->code}"];
            }

            if ($this->isDryRun) {
                $this->dryRunSummary['invoices'][] = [
                    'code'         => $inv->code,
                    'issue_date'   => $inv->issue_date,
                    'subtotal'     => $subtotal,
                    'tax_amount'   => $tax,
                    'total'        => $total,
                    'needs_review' => $needsReview,
                    'lines'        => $lines,
                ];
                $this->printInvoiceDryRun($inv, $lines, $needsReview);
            } else {
                try {
                    DB::transaction(function () use ($inv, $lines) {
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
    }

    /**
     * Build revenue credit lines dùng cùng logic M1 của InvoiceService::buildRevenueLines().
     * Returns [$lines, $needsReview].
     * $needsReview = true nếu có order_item thiếu revenue_account_code (fallback 5111).
     */
    private function buildRevenueLines(Invoice $invoice, int $creditSubtotal): array
    {
        if ($creditSubtotal <= 0) {
            return [[], false];
        }

        // Standalone invoice (không gắn order)
        if (!$invoice->order_id) {
            $account = $invoice->revenue_account_code;
            $needsReview = false;
            if (!$account) {
                $this->warn("  NEEDS_REVIEW {$invoice->code}: standalone invoice không có revenue_account_code — fallback 5111");
                $account     = '5111';
                $needsReview = true;
            }
            return [[
                ['account' => $account, 'debit' => 0, 'credit' => $creditSubtotal,
                 'description' => "Doanh thu ({$account}) - {$invoice->code}"],
            ], $needsReview];
        }

        // Invoice gắn order — đọc revenue_account_code từ order_items
        $groups = DB::table('order_items')
            ->where('order_id', $invoice->order_id)
            ->selectRaw("COALESCE(revenue_account_code, '5111') as account_code,
                         SUM(quantity * unit_price) as group_total")
            ->groupBy('account_code')
            ->orderByDesc('group_total')
            ->get();

        $hasNull = DB::table('order_items')
            ->where('order_id', $invoice->order_id)
            ->whereNull('revenue_account_code')
            ->exists();

        if ($hasNull) {
            $this->warn("  NEEDS_REVIEW {$invoice->code}: có order_item thiếu revenue_account_code — fallback 5111 cho items đó");
        }

        if ($groups->isEmpty()) {
            return [[
                ['account' => '5111', 'debit' => 0, 'credit' => $creditSubtotal,
                 'description' => "Doanh thu - {$invoice->code}"],
            ], true];
        }

        $orderTotal = $groups->sum('group_total');
        if ($orderTotal <= 0) {
            return [[
                ['account' => '5111', 'debit' => 0, 'credit' => $creditSubtotal,
                 'description' => "Doanh thu - {$invoice->code}"],
            ], true];
        }

        $lines     = [];
        $allocated = 0;
        $lastKey   = $groups->keys()->last();

        foreach ($groups as $key => $group) {
            $amount = ($key === $lastKey)
                ? $creditSubtotal - $allocated
                : (int) round($creditSubtotal * ($group->group_total / $orderTotal));

            if ($amount <= 0) continue;

            $lines[]    = ['account' => $group->account_code, 'debit' => 0, 'credit' => $amount,
                           'description' => "Doanh thu ({$group->account_code}) - {$invoice->code}"];
            $allocated += $amount;
        }

        return [$lines ?: [
            ['account' => '5111', 'debit' => 0, 'credit' => $creditSubtotal,
             'description' => "Doanh thu - {$invoice->code}"],
        ], $hasNull];
    }

    private function printInvoiceDryRun(Invoice $inv, array $lines, bool $needsReview): void
    {
        $flag = $needsReview ? ' ⚠ NEEDS_REVIEW' : '';
        $this->line("  [DRY-RUN] {$inv->code} ({$inv->issue_date}){$flag}");
        foreach ($lines as $l) {
            $dr = $l['debit']  > 0 ? number_format($l['debit'])  : '-';
            $cr = $l['credit'] > 0 ? number_format($l['credit']) : '-';
            $this->line("    TK {$l['account']}  Dr={$dr}  Cr={$cr}  [{$l['description']}]");
        }
    }

    // ─── Payments ─────────────────────────────────────────────────────────────

    private function backfillPayments(): void
    {
        $payments = Payment::with('invoice')->get();
        $this->info("Payments: {$payments->count()} cần kiểm tra");

        foreach ($payments as $payment) {
            $invoice = $payment->invoice;
            if (!$invoice) {
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

            $amount = (float) $payment->amount;
            if ($amount <= 0) continue;

            $method = $payment->method instanceof \App\Enums\PaymentMethod
                ? $payment->method->value
                : ($payment->method ?? 'cash');
            $cashAccount = match($method) {
                'bank_transfer', 'bank' => '1121',
                default                 => '1111',
            };

            $lines = [
                ['account' => $cashAccount, 'debit' => (int) $amount, 'credit' => 0,
                 'description' => "Thu tiền - {$invoice->code}"],
                ['account' => '131', 'debit' => 0, 'credit' => (int) $amount,
                 'description' => "Xóa công nợ KH - {$invoice->code}"],
            ];

            if ($this->isDryRun) {
                $this->line("  [DRY-RUN] Payment #{$payment->id} ({$invoice->code})");
                foreach ($lines as $l) {
                    $dr = $l['debit']  > 0 ? number_format($l['debit'])  : '-';
                    $cr = $l['credit'] > 0 ? number_format($l['credit']) : '-';
                    $this->line("    TK {$l['account']}  Dr={$dr}  Cr={$cr}");
                }
            } else {
                try {
                    $this->accounting->post(
                        description: "Thu tiền {$invoice->code}",
                        date: Carbon::parse($payment->payment_date),
                        lines: $lines,
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
    }

    // ─── Stock Entries ────────────────────────────────────────────────────────

    private function backfillStockEntries(): void
    {
        $entries = StockEntry::with('items.product')->where('status', 'confirmed')->get();
        $this->info("StockEntries: {$entries->count()} cần kiểm tra");

        foreach ($entries as $entry) {
            $exists = \App\Models\JournalEntry::where('reference_type', 'stock_entry')
                ->where('reference_id', $entry->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip {$entry->code}: đã có bút toán");
                continue;
            }

            $total = $entry->items->sum(fn ($i) => (float) $i->subtotal);
            if ($total <= 0) continue;

            $lines = [
                ['account' => '156', 'debit' => (int) $total, 'credit' => 0,
                 'description' => $entry->code],
                ['account' => '331', 'debit' => 0, 'credit' => (int) $total,
                 'description' => $entry->code],
            ];

            if ($this->isDryRun) {
                $this->line("  [DRY-RUN] StockEntry {$entry->code}  Dr 156 / Cr 331  " . number_format($total));
            } else {
                try {
                    DB::transaction(function () use ($entry, $lines, $total) {
                        $this->accounting->post(
                            description: "Nhập kho hàng hóa {$entry->code}",
                            date: Carbon::parse($entry->created_at),
                            lines: $lines,
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

    // ─── Stock Exits ──────────────────────────────────────────────────────────

    private function backfillStockExits(): void
    {
        $exits = StockExit::with('items.product')->where('status', 'confirmed')->get();
        $this->info("StockExits: {$exits->count()} cần kiểm tra");

        foreach ($exits as $exit) {
            $exists = \App\Models\JournalEntry::where('reference_type', 'stock_exit')
                ->where('reference_id', $exit->id)
                ->whereRaw("description NOT LIKE 'Đảo:%'")
                ->exists();

            if ($exists) {
                $this->line("  Skip {$exit->code}: đã có bút toán");
                continue;
            }

            $isProject    = $exit->item_usage_type === 'project';
            $debitAccount = $isProject ? '154' : '632';

            $totalCogs = $exit->items->sum(function ($item) {
                $vatRate     = (float) ($item->product?->vat_percent ?? 10);
                $costInclTax = (float) ($item->product?->cost_price ?? 0);
                $divisor     = $vatRate > 0 ? (1 + $vatRate / 100) : 1;
                return (int) round(($costInclTax / $divisor) * $item->quantity);
            });

            if ($totalCogs <= 0) continue;

            $lines = [
                ['account' => $debitAccount, 'debit' => $totalCogs, 'credit' => 0,
                 'description' => "GVHB - {$exit->code}"],
                ['account' => '156', 'debit' => 0, 'credit' => $totalCogs,
                 'description' => "Xuất kho - {$exit->code}"],
            ];

            if ($this->isDryRun) {
                $this->line("  [DRY-RUN] StockExit {$exit->code}  Dr {$debitAccount} / Cr 156  " . number_format($totalCogs));
            } else {
                try {
                    $this->accounting->post(
                        description: "Giá vốn hàng bán {$exit->code}",
                        date: Carbon::parse($exit->exit_date),
                        lines: $lines,
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
    }

    // ─── Dry-run summary ──────────────────────────────────────────────────────

    private function printDryRunSummary(): void
    {
        $invoices    = $this->dryRunSummary['invoices'] ?? [];
        $needsReview = array_filter($invoices, fn ($i) => $i['needs_review']);
        $zeroTax     = array_filter($invoices, fn ($i) => $i['tax_amount'] == 0);
        $hasTax      = array_filter($invoices, fn ($i) => $i['tax_amount'] > 0);

        // Collect unique revenue accounts
        $revenueAccounts = [];
        foreach ($invoices as $inv) {
            foreach ($inv['lines'] as $l) {
                if ($l['credit'] > 0 && $l['account'] !== '33311') {
                    $revenueAccounts[$l['account']] = ($revenueAccounts[$l['account']] ?? 0) + $l['credit'];
                }
            }
        }

        $this->newLine();
        $this->line('═══════════════════════════════════════════════════════');
        $this->line('DRY-RUN SUMMARY — Kết quả kiểm tra backfill');
        $this->line('═══════════════════════════════════════════════════════');
        $this->line('Total invoices to backfill : ' . count($invoices));
        $this->line('Invoices with tax (33311)  : ' . count($hasTax));
        $this->line('Invoices tax_amount = 0    : ' . count($zeroTax));
        $this->line('Invoices NEEDS_REVIEW      : ' . count($needsReview));
        $this->newLine();
        $this->line('Revenue accounts breakdown:');
        foreach ($revenueAccounts as $acc => $amt) {
            $this->line("  TK {$acc}: " . number_format($amt) . ' ₫');
        }
        if ($needsReview) {
            $this->newLine();
            $this->warn('NEEDS_REVIEW invoices (kiểm tra trước khi chạy --force):');
            foreach ($needsReview as $inv) {
                $this->warn("  {$inv['code']} — standalone hoặc thiếu revenue_account_code");
            }
        }
        $this->line('═══════════════════════════════════════════════════════');
        $this->line('Để chạy thật: php artisan accounting:backfill --force');
    }
}
