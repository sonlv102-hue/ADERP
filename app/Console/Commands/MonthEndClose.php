<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Lệnh đóng tháng tổng hợp — chạy tất cả nghiệp vụ cuối tháng theo thứ tự:
 *   1. Khấu hao TSCĐ
 *   2. Phân bổ chi phí trả trước
 *   3. Đánh dấu hóa đơn quá hạn
 */
class MonthEndClose extends Command
{
    protected $signature = 'accounting:month-end {period? : Kỳ YYYY-MM (mặc định: tháng hiện tại)}';
    protected $description = 'Chạy tất cả nghiệp vụ cuối tháng: khấu hao TSCĐ + phân bổ CPT + đánh dấu HĐ quá hạn';

    public function handle(): int
    {
        $period = $this->argument('period') ?: now()->format('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Định dạng kỳ không đúng. Dùng YYYY-MM (vd: 2026-05).");
            return 1;
        }

        $this->info("=== Đóng tháng: {$period} ===");
        $errors = 0;

        // 1. Khấu hao TSCĐ
        $this->info("\n[1/3] Khấu hao TSCĐ...");
        $code = $this->call('assets:depreciate', ['--period' => $period]);
        if ($code !== 0) {
            $this->warn("  Khấu hao TSCĐ có lỗi — xem log.");
            $errors++;
        }

        // 2. Phân bổ chi phí trả trước
        $this->info("\n[2/3] Phân bổ chi phí trả trước...");
        $code = $this->call('accounting:amortize-prepaid', ['--period' => $period]);
        if ($code !== 0) {
            $this->warn("  Phân bổ CPT có lỗi — xem log.");
            $errors++;
        }

        // 3. Đánh dấu hóa đơn quá hạn
        $this->info("\n[3/3] Đánh dấu hóa đơn quá hạn...");
        $this->call('accounting:mark-overdue');

        $this->newLine();
        if ($errors === 0) {
            $this->info("✓ Đóng tháng {$period} hoàn thành.");
        } else {
            $this->warn("Đóng tháng {$period} hoàn thành với {$errors} cảnh báo. Kiểm tra log.");
        }

        return $errors > 0 ? 1 : 0;
    }
}
