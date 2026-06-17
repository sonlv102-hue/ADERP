<?php

namespace App\Console\Commands;

use App\Models\PeriodCloseBatch;
use App\Services\Accounting\PeriodCloseService;
use Illuminate\Console\Command;

class PeriodCloseReverse extends Command
{
    protected $signature = 'period-close:reverse
        {period : Kỳ YYYY-MM}
        {--dry-run : Xem trước không thực hiện đảo}
        {--reason= : Lý do đảo batch (bắt buộc khi --apply)}';

    protected $description = 'Đảo batch kết chuyển cuối kỳ';

    public function __construct(private PeriodCloseService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->argument('period');
        $dryRun = $this->option('dry-run');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Định dạng kỳ không đúng. Dùng YYYY-MM (vd: 2026-06).");
            return 1;
        }

        $batch = PeriodCloseBatch::where('fiscal_period', $period)
            ->where('batch_type', 'monthly')
            ->where('status', 'posted')
            ->latest()
            ->first();

        if (!$batch) {
            $this->error("Không tìm thấy batch kết chuyển active cho kỳ {$period}.");
            return 1;
        }

        $this->info("Batch tìm thấy: {$batch->code} — {$batch->statusLabel()} — {$batch->posted_at?->format('d/m/Y H:i')}");

        if ($dryRun) {
            $this->line("Dry-run: sẽ đảo batch {$batch->code} và tạo bút toán đảo cho {$batch->journal_entry_count} bút toán.");
            return 0;
        }

        $reason = $this->option('reason');
        if (!$reason) {
            $reason = $this->ask("Lý do đảo batch:");
            if (!$reason) {
                $this->error("Lý do không được để trống.");
                return 1;
            }
        }

        $userId = \App\Models\User::where('is_active', true)->value('id') ?? 1;

        try {
            $this->service->reverseBatch($batch, $userId, $reason);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info("✓ Đã đảo batch {$batch->code} kỳ {$period}.");
        return 0;
    }
}
