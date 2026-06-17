<?php

namespace App\Console\Commands;

use App\Services\Accounting\PeriodCloseService;
use Illuminate\Console\Command;

class PeriodCloseYearOpen extends Command
{
    protected $signature = 'period-close:year-open
        {year : Năm tài chính cần chuyển lợi nhuận (vd: 2026)}
        {--dry-run : Xem trước không tạo dữ liệu}
        {--apply   : Thực hiện chuyển lợi nhuận}
        {--notes=  : Ghi chú cho batch}';

    protected $description = 'Chuyển lợi nhuận/lỗ năm $year từ TK 4212 sang TK 4211 (đầu năm mới)';

    public function __construct(private PeriodCloseService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $year   = (int) $this->argument('year');
        $dryRun = $this->option('dry-run');
        $apply  = $this->option('apply');

        if ($year < 2020 || $year > 2099) {
            $this->error("Năm không hợp lệ: {$year}");
            return 1;
        }

        if (!$dryRun && !$apply) {
            $this->error("Phải chỉ định --dry-run hoặc --apply.");
            return 1;
        }

        try {
            $plan = $this->service->buildYearEndTransfer($year);
        } catch (\Throwable $e) {
            $this->error("Lỗi tính toán: " . $e->getMessage());
            return 1;
        }

        $this->info("=== Kế hoạch chuyển lợi nhuận năm {$year} ===");
        $this->line("  {$plan['description']}");

        if (empty($plan['lines'])) {
            $this->warn($plan['description']);
            return 0;
        }

        $this->newLine();
        $this->line("<fg=cyan>Bút toán dự kiến:</>");
        foreach ($plan['lines'] as $l) {
            $this->line(sprintf("  TK %-6s  Nợ: %15s  Có: %15s  %s",
                $l['account'],
                $l['debit'] > 0 ? number_format($l['debit']) : '—',
                $l['credit'] > 0 ? number_format($l['credit']) : '—',
                $l['description']
            ));
        }

        $this->line("  Ngày bút toán : {$plan['entryDate']}");
        $this->line("  Kỳ kế toán   : {$plan['fiscalPeriod']}");

        if ($dryRun) {
            $this->newLine();
            $this->info("Dry-run hoàn thành. Chạy lại với --apply để thực hiện.");
            return 0;
        }

        $this->newLine();
        $this->warn("CẢNH BÁO: Thao tác này sẽ tạo bút toán chuyển lợi nhuận và KHÔNG THỂ HOÀN TÁC tự động sau khi kỳ 12/{$year} bị khóa.");

        if (!$this->confirm("Xác nhận thực hiện chuyển lợi nhuận năm {$year}?")) {
            $this->line("Đã hủy.");
            return 0;
        }

        $userId = \App\Models\User::where('is_active', true)->value('id') ?? 1;

        try {
            $batch = $this->service->closeYearEnd($year, $userId, $this->option('notes'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info("✓ Chuyển lợi nhuận năm {$year} thành công!");
        $this->line("  Batch: {$batch->code}");

        return 0;
    }
}
