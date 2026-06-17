<?php

namespace App\Console\Commands;

use App\Services\Accounting\PeriodCloseService;
use Illuminate\Console\Command;

class PeriodCloseRun extends Command
{
    protected $signature = 'period-close:run
        {period : Kỳ YYYY-MM}
        {--dry-run : Xem trước không tạo dữ liệu}
        {--apply   : Thực hiện kết chuyển (bắt buộc khai báo để tạo)}
        {--notes=  : Ghi chú cho batch kết chuyển}
        {--force   : Bỏ qua cảnh báo không nghiêm trọng}';

    protected $description = 'Tạo batch kết chuyển cuối kỳ (doanh thu/chi phí → TK 911 → TK 4212)';

    public function __construct(private PeriodCloseService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $period  = $this->argument('period');
        $dryRun  = $this->option('dry-run');
        $apply   = $this->option('apply');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Định dạng kỳ không đúng. Dùng YYYY-MM (vd: 2026-06).");
            return 1;
        }

        if (!$dryRun && !$apply) {
            $this->error("Phải chỉ định --dry-run hoặc --apply.");
            $this->line("  --dry-run: xem trước kế hoạch kết chuyển");
            $this->line("  --apply:   thực hiện tạo batch kết chuyển");
            return 1;
        }

        if ($dryRun) {
            return $this->call('period-close:preview', ['period' => $period]);
        }

        // --apply
        $this->info("Kết chuyển kỳ {$period}...");

        try {
            $preview = $this->service->preview($period);
        } catch (\Throwable $e) {
            $this->error("Lỗi preview: " . $e->getMessage());
            return 1;
        }

        if ($preview['hasCritical']) {
            $criticals = array_filter($preview['warnings'], fn ($w) => $w['type'] === 'critical');
            foreach ($criticals as $w) {
                $this->error("[{$w['code']}] {$w['message']}");
            }
            return 1;
        }

        $warnings = array_filter($preview['warnings'], fn ($w) => $w['type'] === 'warning');
        if (!empty($warnings) && !$this->option('force')) {
            foreach ($warnings as $w) {
                $this->warn("[{$w['code']}] {$w['message']}");
            }
            if (!$this->confirm("Còn cảnh báo ở trên. Vẫn tiếp tục kết chuyển?")) {
                $this->line("Hủy. Chạy lại với --force để bỏ qua cảnh báo không nghiêm trọng.");
                return 0;
            }
        }

        $userId = \App\Models\User::where('is_active', true)->value('id') ?? 1;

        try {
            $batch = $this->service->closeWithBatch($period, $userId, $this->option('notes'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->info("✓ Kết chuyển kỳ {$period} thành công!");
        $this->line("  Batch: {$batch->code}");
        $this->line("  Doanh thu: " . number_format($batch->total_revenue) . " ₫");
        $this->line("  Chi phí:   " . number_format($batch->total_expense) . " ₫");
        $pnl = $batch->profit_or_loss;
        $this->line("  " . ($pnl >= 0 ? "Lợi nhuận" : "Lỗ") . ": " . number_format(abs($pnl)) . " ₫");
        $this->line("  Số bút toán: {$batch->journal_entry_count}");

        return 0;
    }
}
