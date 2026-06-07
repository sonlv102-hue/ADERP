<?php

namespace App\Console\Commands;

use App\Enums\AccountingPostingStatus;
use App\Models\AccountingPostingJob;
use App\Services\AccountingService;
use Illuminate\Console\Command;

class RetryFailedAccountingPostings extends Command
{
    protected $signature = 'accounting:retry-failed
                            {--source-type= : Lọc theo loại chứng từ (invoice, stock_entry, ...)}
                            {--dry-run : Chỉ liệt kê, không retry}';

    protected $description = 'Retry tất cả auto-posting jobs đang ở trạng thái failed';

    public function handle(AccountingService $accounting): int
    {
        $query = AccountingPostingJob::where('status', AccountingPostingStatus::Failed);

        if ($type = $this->option('source-type')) {
            $query->where('source_type', $type);
        }

        $jobs = $query->orderBy('id')->get();

        if ($jobs->isEmpty()) {
            $this->info('Không có posting job nào bị lỗi.');
            return Command::SUCCESS;
        }

        $this->info("Tìm thấy {$jobs->count()} job(s) cần retry.");

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Source', 'Type', 'Error', 'Attempts', 'Posting Date'],
                $jobs->map(fn ($j) => [
                    $j->id,
                    "{$j->source_type}#{$j->source_id}",
                    $j->posting_type,
                    $j->error_code,
                    $j->attempts,
                    $j->posting_date?->format('Y-m-d'),
                ])
            );
            return Command::SUCCESS;
        }

        $success = 0;
        $failed  = 0;

        foreach ($jobs as $job) {
            try {
                $accounting->retryJob($job);
                $this->line("  [OK] {$job->source_type}#{$job->source_id}/{$job->posting_type}");
                $success++;
            } catch (\Throwable $e) {
                $this->warn("  [FAIL] {$job->source_type}#{$job->source_id}/{$job->posting_type}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Hoàn thành: {$success} thành công, {$failed} thất bại.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
