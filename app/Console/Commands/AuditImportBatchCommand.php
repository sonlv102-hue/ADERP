<?php

namespace App\Console\Commands;

use App\Models\BankStatementImport;
use App\Models\BankStatementImportBatch;
use App\Models\BankStatementImportRow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditImportBatchCommand extends Command
{
    protected $signature = 'bank-statements:audit-import-batch
                            {--batch-id= : ID của batch cần kiểm tra}
                            {--all : Kiểm tra tất cả batch (trừ cancelled)}';

    protected $description = 'Audit bank statement import batches for data integrity issues.';

    public function handle(): int
    {
        $batches = $this->resolveBatches();
        if ($batches->isEmpty()) {
            $this->error('Không tìm thấy batch nào.'); return 1;
        }

        $totalIssues = 0;
        foreach ($batches as $batch) {
            $issues = $this->auditBatch($batch);
            $totalIssues += count($issues);
            $this->line('');
            $this->info("Batch #{$batch->id} | status={$batch->status} | files={$batch->total_files} | valid={$batch->total_rows_valid} | dup={$batch->total_rows_duplicate} | err={$batch->total_rows_error}");
            if (empty($issues)) {
                $this->line('  ✓ Không phát hiện vấn đề.');
            } else {
                foreach ($issues as $issue) { $this->warn("  ✗ {$issue}"); }
            }
        }

        $this->line('');
        $this->info("Tổng: {$totalIssues} vấn đề trong " . $batches->count() . ' batch.');
        return $totalIssues > 0 ? 1 : 0;
    }

    private function resolveBatches()
    {
        if ($id = $this->option('batch-id')) {
            return BankStatementImportBatch::where('id', $id)->get();
        }
        $q = BankStatementImportBatch::whereNotIn('status', ['cancelled'])->orderByDesc('id');
        return $this->option('all') ? $q->get() : $q->take(10)->get();
    }

    private function auditBatch(BankStatementImportBatch $batch): array
    {
        $issues = [];

        // 1. Valid rows without bank_transaction after import
        if ($batch->status === 'imported') {
            $orphan = BankStatementImportRow::where('batch_id', $batch->id)
                ->where('parse_status', 'valid')->whereNull('bank_transaction_id')->count();
            if ($orphan > 0) $issues[] = "{$orphan} dòng valid chưa có bank_transaction (batch đã imported).";
        }

        // 2. Duplicate rows that somehow got a bank_transaction
        $dupWithTx = BankStatementImportRow::where('batch_id', $batch->id)
            ->where('parse_status', 'duplicate')->whereNotNull('bank_transaction_id')->count();
        if ($dupWithTx > 0) $issues[] = "{$dupWithTx} dòng duplicate có bank_transaction — không nhất quán.";

        // 3. account_mismatch files that have linked bank_transactions
        $mismatch = BankStatementImport::where('batch_id', $batch->id)
            ->where('status', 'account_mismatch')
            ->whereHas('rows', fn($q) => $q->whereNotNull('bank_transaction_id'))->count();
        if ($mismatch > 0) $issues[] = "{$mismatch} file sai số TK có dòng đã import.";

        // 4. Duplicate transaction_no among imported rows
        if ($batch->status === 'imported') {
            $dupNo = BankStatementImportRow::where('batch_id', $batch->id)
                ->whereNotNull('bank_transaction_id')->whereNotNull('transaction_no')
                ->select('transaction_no', DB::raw('COUNT(*) c'))
                ->groupBy('transaction_no')->having('c', '>', 1)->count();
            if ($dupNo > 0) $issues[] = "{$dupNo} nhóm transaction_no trùng trong cùng batch.";
        }

        // 5. Valid rows missing date or amount
        $noData = BankStatementImportRow::where('batch_id', $batch->id)
            ->where('parse_status', 'valid')
            ->where(fn($q) => $q->whereNull('transaction_date')
                ->orWhere(fn($q2) => $q2->where('debit_amount', 0)->where('credit_amount', 0)))
            ->count();
        if ($noData > 0) $issues[] = "{$noData} dòng valid thiếu ngày hoặc số tiền.";

        // 6. Rows with both debit and credit > 0
        $both = BankStatementImportRow::where('batch_id', $batch->id)
            ->where('debit_amount', '>', 0)->where('credit_amount', '>', 0)->count();
        if ($both > 0) $issues[] = "{$both} dòng vừa có tiền vào vừa có tiền ra.";

        return $issues;
    }
}
