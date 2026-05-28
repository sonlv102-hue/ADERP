<?php

namespace App\Console\Commands;

use App\Services\PrepaidExpenseService;
use Illuminate\Console\Command;

class AmortizePrepaidExpenses extends Command
{
    protected $signature = 'accounting:amortize-prepaid {--period= : Kỳ YYYY-MM (mặc định: tháng hiện tại)}';
    protected $description = 'Phân bổ chi phí trả trước hàng tháng (TK 142/242 → TK 6xx)';

    public function handle(PrepaidExpenseService $service): int
    {
        $period = $this->option('period') ?: now()->format('Y-m');

        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $this->error("Định dạng kỳ không đúng. Dùng YYYY-MM (vd: 2026-05).");
            return 1;
        }

        $this->info("Phân bổ chi phí trả trước kỳ: {$period}");

        $result = $service->runMonthlyAmortization($period);

        $this->table(['Chỉ số', 'Số lượng'], [
            ['Đã phân bổ', $result['processed']],
            ['Bỏ qua',     $result['skipped']],
            ['Lỗi',        count($result['errors'])],
        ]);

        foreach ($result['errors'] as $err) {
            $this->error($err);
        }

        return count($result['errors']) > 0 ? 1 : 0;
    }
}
