<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra các dòng bút toán TK 154 không có project_id.
 * Usage: php artisan projects:cost-link-audit
 */
class ProjectCostLinkAuditCommand extends Command
{
    protected $signature = 'projects:cost-link-audit';

    protected $description = 'Rà soát journal_entry_lines TK 154 thiếu project_id và purchase_invoice_items TK 154 thiếu project_id.';

    public function handle(): int
    {
        $this->info('=== Kiểm tra liên kết chi phí dự án (TK 154) ===');
        $this->line('');

        $totalIssues = 0;
        $totalIssues += $this->auditJournalLines();
        $totalIssues += $this->auditInvoiceItems();
        $totalIssues += $this->auditStockExits();

        $this->line('');
        if ($totalIssues === 0) {
            $this->info('✓ Không tìm thấy vấn đề nào.');
            return self::SUCCESS;
        }

        $this->warn("Tổng: {$totalIssues} vấn đề cần xử lý.");
        $this->line('Chạy <options=bold>php artisan projects:backfill-cost-links --dry-run</> để xem kế hoạch backfill.');

        return self::FAILURE;
    }

    // ─── A: Journal entry lines ────────────────────────────────────────────────

    private function auditJournalLines(): int
    {
        $this->line('[A] Kiểm tra journal_entry_lines có TK 154 nhưng thiếu project_id...');

        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_code', 'like', '154%')
            ->whereNull('jl.project_id')
            ->whereNotIn('je.status', ['draft', 'voided'])
            ->select(
                'jl.id',
                'je.code as je_code',
                'je.entry_date',
                'jl.account_code',
                'jl.debit',
                'jl.credit',
                'jl.description',
                'je.reference_type',
                'je.reference_id'
            )
            ->orderBy('je.entry_date')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  ✓ Không có dòng JE TK 154 nào thiếu project_id.');
            return 0;
        }

        $this->warn("  Tìm thấy {$rows->count()} dòng JE TK 154 thiếu project_id:");

        $headers = ['JE', 'Ngày', 'TK', 'Nợ', 'Có', 'Source', 'Diễn giải'];
        $data    = $rows->map(fn ($r) => [
            $r->je_code,
            $r->entry_date,
            $r->account_code,
            number_format($r->debit),
            number_format($r->credit),
            "{$r->reference_type}#{$r->reference_id}",
            mb_substr($r->description ?? '', 0, 40),
        ])->toArray();

        $this->table($headers, $data);

        return $rows->count();
    }

    // ─── B: Purchase invoice items ─────────────────────────────────────────────

    private function auditInvoiceItems(): int
    {
        $this->line('[B] Kiểm tra purchase_invoice_items có TK 154 nhưng thiếu project_id...');

        $rows = DB::table('purchase_invoice_items as pii')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'pii.purchase_invoice_id')
            ->where('pii.account_code', 'like', '154%')
            ->whereNull('pii.project_id')
            ->select('pi.code as pi_code', 'pii.id', 'pii.account_code', 'pii.amount', 'pii.description')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  ✓ Không có dòng HĐ mua TK 154 nào thiếu project_id.');
            return 0;
        }

        $this->warn("  Tìm thấy {$rows->count()} dòng purchase_invoice_items TK 154 thiếu project_id:");
        $this->table(
            ['HĐ mua', 'Item ID', 'TK', 'Số tiền', 'Diễn giải'],
            $rows->map(fn ($r) => [
                $r->pi_code,
                $r->id,
                $r->account_code,
                number_format($r->amount),
                mb_substr($r->description ?? '', 0, 40),
            ])->toArray()
        );

        return $rows->count();
    }

    // ─── C: Stock exits ────────────────────────────────────────────────────────

    private function auditStockExits(): int
    {
        $this->line('[C] Kiểm tra phiếu xuất kho mục đích dự án nhưng thiếu project_id trên stock_movements...');

        $rows = DB::table('stock_exits as xe')
            ->join('stock_movements as sm', function ($j) {
                $j->on('sm.source_id', '=', 'xe.id')
                  ->where('sm.source_type', '=', 'App\\Models\\StockExit');
            })
            ->where('xe.status', 'confirmed')
            ->whereNull('sm.project_id')
            ->whereNotNull('xe.project_id')
            ->select('xe.code', 'xe.project_id', 'sm.id as movement_id')
            ->distinct()
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  ✓ Không có stock_movements nào từ phiếu XK dự án thiếu project_id.');
            return 0;
        }

        $this->warn("  Tìm thấy {$rows->count()} movement từ XK dự án thiếu project_id:");
        $this->table(
            ['Phiếu XK', 'Project ID', 'Movement ID'],
            $rows->map(fn ($r) => [$r->code, $r->project_id, $r->movement_id])->toArray()
        );

        return $rows->count();
    }
}
