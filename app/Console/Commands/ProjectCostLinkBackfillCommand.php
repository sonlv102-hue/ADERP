<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill project_id trên journal_entry_lines và purchase_invoice_items TK 154.
 *
 * Chiến lược:
 *  1. JE line source = purchase_invoice → lấy project_id từ purchase_invoice_items nếu item đó có project_id xác định.
 *  2. JE line source = purchase_invoice và invoice có project_id header → dùng header project_id.
 *  3. JE line source = App\Models\StockExit → lấy project_id từ stock_exits.
 *  4. purchase_invoice_items thiếu project_id nhưng invoice.project_id có → backfill từ header.
 *  Không tự gán nếu không xác định chắc chắn.
 *
 * Usage:
 *   php artisan projects:backfill-cost-links --dry-run
 *   php artisan projects:backfill-cost-links --apply
 */
class ProjectCostLinkBackfillCommand extends Command
{
    protected $signature = 'projects:backfill-cost-links
                            {--dry-run : Chỉ hiển thị kế hoạch, không ghi DB}
                            {--apply  : Thực sự ghi vào DB}';

    protected $description = 'Backfill project_id cho JE lines và PI items TK 154 thiếu project_id.';

    private bool $dryRun = true;
    private int  $fixed  = 0;
    private int  $skipped = 0;

    public function handle(): int
    {
        $this->dryRun = !$this->option('apply');

        if ($this->dryRun && !$this->option('dry-run')) {
            $this->warn('Không có --dry-run hoặc --apply. Chạy ở chế độ dry-run mặc định.');
        }

        $mode = $this->dryRun ? '[DRY-RUN]' : '[APPLY]';
        $this->info("=== Backfill project_id TK 154 {$mode} ===");
        $this->line('');

        $this->backfillInvoiceItems();
        $this->backfillJeFromInvoice();
        $this->backfillJeFromStockExit();

        $this->line('');
        $this->info("Kết quả: fixed={$this->fixed}, skipped_no_source={$this->skipped}");

        if ($this->dryRun) {
            $this->comment('Chạy với --apply để áp dụng thay đổi.');
        }

        return self::SUCCESS;
    }

    // ─── 1. Backfill purchase_invoice_items từ header project_id ──────────────

    private function backfillInvoiceItems(): void
    {
        $this->line('[1] Backfill purchase_invoice_items từ invoice.project_id...');

        $rows = DB::table('purchase_invoice_items as pii')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'pii.purchase_invoice_id')
            ->where('pii.account_code', 'like', '154%')
            ->whereNull('pii.project_id')
            ->whereNotNull('pi.project_id')
            ->select('pii.id', 'pi.code as pi_code', 'pi.project_id', 'pii.account_code')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  Không có dòng nào cần backfill.');
            return;
        }

        foreach ($rows as $row) {
            $this->line("  → pii#{$row->id} ({$row->pi_code} / {$row->account_code}) ← project_id={$row->project_id}");
            if (!$this->dryRun) {
                DB::table('purchase_invoice_items')
                    ->where('id', $row->id)
                    ->update(['project_id' => $row->project_id]);
            }
            $this->fixed++;
        }
    }

    // ─── 2. Backfill JE lines từ purchase_invoice project_id ─────────────────

    private function backfillJeFromInvoice(): void
    {
        $this->line('[2] Backfill journal_entry_lines từ purchase_invoice.project_id...');

        // JE line source = purchase_invoice, line TK 154, thiếu project_id, nhưng invoice có project_id
        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('purchase_invoices as pi', function ($j) {
                $j->on('je.reference_id', '=', 'pi.id')
                  ->where('je.reference_type', '=', 'purchase_invoice');
            })
            ->where('jl.account_code', 'like', '154%')
            ->whereNull('jl.project_id')
            ->whereNotNull('pi.project_id')
            ->whereNotIn('je.status', ['draft', 'voided'])
            ->select('jl.id', 'je.code as je_code', 'pi.project_id', 'pi.code as pi_code')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  Không có dòng nào từ PI có thể backfill.');
            return;
        }

        foreach ($rows as $row) {
            $this->line("  → jl#{$row->id} ({$row->je_code} / PI {$row->pi_code}) ← project_id={$row->project_id}");
            if (!$this->dryRun) {
                DB::table('journal_entry_lines')
                    ->where('id', $row->id)
                    ->update(['project_id' => $row->project_id]);
            }
            $this->fixed++;
        }
    }

    // ─── 3. Backfill JE lines từ StockExit project_id ─────────────────────────

    private function backfillJeFromStockExit(): void
    {
        $this->line('[3] Backfill journal_entry_lines từ stock_exits.project_id...');

        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('stock_exits as xe', function ($j) {
                $j->on('je.reference_id', '=', 'xe.id')
                  ->where('je.reference_type', '=', 'App\\Models\\StockExit');
            })
            ->where('jl.account_code', 'like', '154%')
            ->whereNull('jl.project_id')
            ->whereNotNull('xe.project_id')
            ->whereNotIn('je.status', ['draft', 'voided'])
            ->select('jl.id', 'je.code as je_code', 'xe.project_id', 'xe.code as xe_code')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  Không có dòng nào từ StockExit có thể backfill.');
            return;
        }

        foreach ($rows as $row) {
            $this->line("  → jl#{$row->id} ({$row->je_code} / XK {$row->xe_code}) ← project_id={$row->project_id}");
            if (!$this->dryRun) {
                DB::table('journal_entry_lines')
                    ->where('id', $row->id)
                    ->update(['project_id' => $row->project_id]);
            }
            $this->fixed++;
        }
    }
}
