<?php

namespace App\Console\Commands;

use App\Services\Accounting\PeriodCloseService;
use Illuminate\Console\Command;

/**
 * Kết chuyển cuối kỳ: DT/CP → TK 911 → TK 4212.
 * Chạy sau khi đã xử lý xong các nghiệp vụ trong tháng.
 *
 * Idempotent: nếu đã kết chuyển, lệnh từ chối và báo lỗi.
 * Dùng `accounting:void-period-close {period}` để hủy rồi chạy lại.
 */
class AccountingPeriodClose extends Command
{
    protected $signature = 'accounting:period-close
        {period? : Kỳ YYYY-MM (mặc định: tháng hiện tại)}';

    protected $description = 'Kết chuyển cuối kỳ: doanh thu/chi phí → TK 911 → TK 4212';

    public function __construct(private PeriodCloseService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->argument('period') ?: now()->format('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Định dạng kỳ không đúng. Dùng YYYY-MM (vd: 2026-06).");
            return 1;
        }

        $this->info("Kết chuyển cuối kỳ: {$period}");

        try {
            $jes = $this->service->close($period);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        if (empty($jes)) {
            $this->warn("Không có phát sinh doanh thu/chi phí trong kỳ {$period}. Không tạo bút toán.");
            return 0;
        }

        foreach ($jes as $je) {
            $total = $je->lines->sum('debit');
            $this->line("  ✓ {$je->code} — {$je->description} — " . number_format($total) . " ₫");
        }

        $this->info("Kết chuyển kỳ {$period} hoàn thành. Tạo " . count($jes) . " bút toán.");
        return 0;
    }
}
