<?php

namespace App\Console\Commands;

use App\Services\Accounting\PeriodCloseService;
use Illuminate\Console\Command;

class PeriodClosePreview extends Command
{
    protected $signature = 'period-close:preview
        {period : Kỳ YYYY-MM}';

    protected $description = 'Xem trước kế hoạch kết chuyển cuối kỳ (dry-run, không tạo dữ liệu)';

    public function __construct(private PeriodCloseService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->argument('period');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Định dạng kỳ không đúng. Dùng YYYY-MM (vd: 2026-06).");
            return 1;
        }

        $this->info("=== Preview kết chuyển kỳ {$period} ===");

        try {
            $result = $this->service->preview($period);
        } catch (\Throwable $e) {
            $this->error("Lỗi: " . $e->getMessage());
            return 1;
        }

        // Checklist
        $this->newLine();
        $this->line('<fg=cyan>--- Checklist nghiệp vụ định kỳ ---</>');
        foreach ($result['checklist'] as $item) {
            $icon = match($item['status']) {
                'ok'           => '<fg=green>✓</>',
                'warning'      => '<fg=yellow>!</>',
                'missing'      => '<fg=red>✗</>',
                'needs_review' => '<fg=yellow>?</>',
                'info'         => '<fg=blue>i</>',
                'skip'         => '<fg=gray>-</>',
                default        => ' ',
            };
            $this->line("  {$icon} {$item['label']}: {$item['message']}");
        }

        // Cảnh báo
        if (!empty($result['warnings'])) {
            $this->newLine();
            $this->line('<fg=cyan>--- Cảnh báo ---</>');
            foreach ($result['warnings'] as $w) {
                $color = match($w['type']) {
                    'critical' => 'red',
                    'warning'  => 'yellow',
                    default    => 'blue',
                };
                $this->line("  <fg={$color}>[{$w['type']}]</> [{$w['code']}] {$w['message']}");
            }
        }

        // Doanh thu
        if (!empty($result['incomeSections'])) {
            $this->newLine();
            $this->line('<fg=cyan>--- Doanh thu kết chuyển ---</>');
            foreach ($result['incomeSections'] as $s) {
                $this->line(sprintf("  TK %-6s %-45s KC: %s ₫  (%s)",
                    $s['code'], mb_substr($s['name'], 0, 45),
                    number_format($s['closing_amount']), $s['entry_text']
                ));
            }
        }

        // Chi phí
        if (!empty($result['expenseSections'])) {
            $this->newLine();
            $this->line('<fg=cyan>--- Chi phí kết chuyển ---</>');
            foreach ($result['expenseSections'] as $s) {
                $this->line(sprintf("  TK %-6s %-45s KC: %s ₫  (%s)",
                    $s['code'], mb_substr($s['name'], 0, 45),
                    number_format($s['closing_amount']), $s['entry_text']
                ));
            }
        }

        // Kết quả
        $this->newLine();
        $this->line('<fg=cyan>--- Kết quả ---</>');
        $this->line("  Tổng doanh thu : " . number_format($result['totalRevenue']) . " ₫");
        $this->line("  Tổng chi phí   : " . number_format($result['totalExpense']) . " ₫");
        $pnl = $result['profitOrLoss'];
        $pnlLabel = $pnl >= 0 ? 'Lợi nhuận' : 'Lỗ';
        $pnlColor = $pnl >= 0 ? 'green' : 'red';
        $this->line("  <fg={$pnlColor}>{$pnlLabel}      : " . number_format(abs($pnl)) . " ₫</>");

        if ($result['hasCritical']) {
            $this->newLine();
            $this->error("Không thể kết chuyển do có cảnh báo nghiêm trọng.");
            return 1;
        }

        $this->newLine();
        $this->info($result['canClose']
            ? "Kỳ {$period} sẵn sàng kết chuyển. Chạy: php artisan period-close:run {$period} --apply"
            : "Kỳ {$period} chưa thể kết chuyển. Kiểm tra cảnh báo ở trên."
        );

        return 0;
    }
}
