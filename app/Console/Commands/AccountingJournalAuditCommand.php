<?php

namespace App\Console\Commands;

use App\Services\Accounting\JournalAuditService;
use Illuminate\Console\Command;

class AccountingJournalAuditCommand extends Command
{
    protected $signature = 'accounting:journal-audit
                            {--from= : Từ ngày (YYYY-MM-DD)}
                            {--to=   : Đến ngày (YYYY-MM-DD)}
                            {--severity= : Lọc theo mức độ: critical|warning}
                            {--type=  : Lọc theo mã lỗi: E001,E002,...}';

    protected $description = 'Rà soát bút toán kế toán — phát hiện thiếu JE, JE sai, tài khoản cha, giá vốn thiếu.';

    public function handle(JournalAuditService $service): int
    {
        $options = [
            'from' => $this->option('from'),
            'to'   => $this->option('to'),
        ];

        $this->info('Đang rà soát bút toán kế toán...');
        if ($options['from'] || $options['to']) {
            $this->line("  Phạm vi: " . ($options['from'] ?? '*') . " → " . ($options['to'] ?? '*'));
        }

        $findings = $service->run($options);

        // Filter by severity/type if specified
        $severityFilter = $this->option('severity');
        $typeFilter     = $this->option('type') ? explode(',', $this->option('type')) : null;

        if ($severityFilter) {
            $findings = array_filter($findings, fn($f) => $f['severity'] === $severityFilter);
        }
        if ($typeFilter) {
            $findings = array_filter($findings, fn($f) => in_array($f['error_code'], $typeFilter));
        }

        $findings = array_values($findings);

        if (empty($findings)) {
            $this->info('✓ Không tìm thấy vấn đề nào trong phạm vi được kiểm tra.');
            return Command::SUCCESS;
        }

        $critical = count(array_filter($findings, fn($f) => $f['severity'] === 'critical'));
        $warning  = count(array_filter($findings, fn($f) => $f['severity'] === 'warning'));

        $this->newLine();
        $this->error("  Tổng: {$critical} lỗi nghiêm trọng, {$warning} cảnh báo  ");
        $this->newLine();

        // Group by error_code
        $grouped = collect($findings)->groupBy('error_code');

        foreach ($grouped as $code => $group) {
            $label    = JournalAuditService::ERROR_CODES[$code]['label'] ?? $code;
            $severity = JournalAuditService::ERROR_CODES[$code]['severity'] ?? 'warning';
            $icon     = $severity === 'critical' ? '✗' : '⚠';
            $count    = count($group);

            $this->line("{$icon} <fg=" . ($severity === 'critical' ? 'red' : 'yellow') . ">[{$code}] {$label} ({$count} trường hợp)</>");

            foreach ($group as $f) {
                $doc  = $f['document_code'] ?? "{$f['document_type']}#{$f['document_id']}";
                $date = $f['document_date'] ? substr($f['document_date'], 0, 10) : '—';
                $amt  = $f['document_amount'] !== null ? ' | ' . number_format($f['document_amount'], 0, ',', '.') . ' đ' : '';
                $this->line("   → {$doc} ({$date}){$amt}");
                $this->line("     {$f['description']}");
            }
            $this->newLine();
        }

        $this->line('<fg=cyan>Mở màn hình Kế toán → Rà soát bút toán để xem chi tiết và xử lý từng trường hợp.</>');

        return $critical > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
