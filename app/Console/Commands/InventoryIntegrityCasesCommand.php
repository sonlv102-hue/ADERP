<?php

namespace App\Console\Commands;

use App\Models\InventoryIntegrityIssue as Issue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gắn phương án xử lý (do kế toán/DEV lập, file JSON NGOÀI Git) vào vấn đề đã được audit phát hiện.
 * - Khớp theo định danh nghiệp vụ ổn định (quy tắc + mã SP + kho / mã chứng từ / chủ đề chính sách), KHÔNG theo ID VD-xx.
 * - "expect": số liệu lúc lập phương án; nếu dữ liệu hiện tại khác → đánh dấu needs_reverify (cần xác minh lại).
 * - Chạy lại không sinh trùng (bỏ qua nếu nội dung không đổi); không đụng ghi chú/kết quả xử lý; chỉ đổi trạng thái khi đang OPEN.
 * - Không tạo vấn đề mới, không sửa số liệu phát hiện, không đụng dữ liệu kho/kế toán.
 *
 * File: [{"id": "V4-1", "match": {"rule", "product_codes", "warehouse_id", "document", "policy"},
 *         "expect": {"count": 4, "value_diff_sum": 214689000, "qty_diff_sum": -7335, "tolerance": 1},
 *         "case": {"title", "summary", "options": [...], "to_confirm": [...], "source"}, "category": "ACCOUNTING", "status": "PENDING_ACCOUNTING"}]
 */
class InventoryIntegrityCasesCommand extends Command
{
    protected $signature = 'inventory:integrity-cases {file : Đường dẫn file JSON} {--dry-run : Chỉ hiển thị, không ghi}';

    protected $description = 'Gắn phương án xử lý (case) vào vấn đề của báo cáo Kiểm soát sai lệch tồn kho và kế toán';

    public function handle(): int
    {
        $cases = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($cases)) {
            $this->error('File JSON không hợp lệ.');
            return self::FAILURE;
        }
        $stats = ['attached' => 0, 'unchanged' => 0, 'reverify' => 0, 'unmatched' => 0];
        foreach ($cases as $n => $c) {
            $id = $c['id'] ?? 'case-' . ($n + 1);
            $issues = $this->match($c['match'] ?? []);
            [$ok, $reason] = $this->verify($issues, $c['expect'] ?? []);
            $this->line(sprintf('%s %s → %d vấn đề%s', $id, $c['case']['title'] ?? '', $issues->count(), $ok ? '' : " — CẦN XÁC MINH LẠI: {$reason}"));
            if ($issues->isEmpty()) {
                $stats['unmatched']++;
                continue;
            }
            if (! $ok) {
                $stats['reverify']++;
            }
            if ($this->option('dry-run')) {
                continue;
            }
            DB::transaction(function () use ($issues, $c, $id, $ok, $reason, &$stats) {
                foreach ($issues as $issue) {
                    $stats[$this->attach($issue, $c, $id, $ok, $reason) ? 'attached' : 'unchanged']++;
                }
            });
        }
        $this->info(($this->option('dry-run') ? 'Dry-run (không ghi): ' : '') . json_encode($stats, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function attach(Issue $issue, array $c, string $id, bool $ok, ?string $reason): bool
    {
        $case = ($c['case'] ?? []) + ['case_id' => $id, 'needs_reverify' => ! $ok, 'reverify_reason' => $ok ? null : $reason];
        $hash = md5(json_encode($case));
        $current = $issue->case_info ?? [];
        if (($current['case_hash'] ?? null) === $hash) {
            return false; // đã gắn đúng nội dung → chạy lại không sinh bản ghi/log trùng
        }
        $from = $issue->status;
        $issue->case_info = $case + ['case_hash' => $hash, 'attached_at' => now()->toIso8601String()];
        if (! empty($c['category']) && in_array($c['category'], Issue::CATEGORIES, true) && ($current['case_id'] ?? null) === null) {
            $issue->category = $c['category'];
        }
        if ($issue->status === 'OPEN') {   // không ghi đè trạng thái người dùng đã đặt
            $target = $ok ? ($c['status'] ?? null) : 'PENDING_VERIFICATION';
            if ($target && in_array($target, Issue::MANUAL_STATUSES, true)) {
                $issue->status = $target;
            }
        }
        $issue->save();   // resolution / ghi chú / người phụ trách giữ nguyên
        $issue->log(isset($current['case_id']) ? 'case_updated' : 'case', ['from_status' => $from, 'to_status' => $issue->status,
            'note' => "Gắn phương án {$id}: " . ($c['case']['title'] ?? '') . ($ok ? '' : " — cần xác minh lại: {$reason}"),
            'payload' => ['case_id' => $id, 'source' => $c['case']['source'] ?? null, 'needs_reverify' => ! $ok]]);
        return true;
    }

    /** So số liệu hiện tại với số liệu lúc lập phương án. */
    private function verify($issues, array $expect): array
    {
        $tol = (float) ($expect['tolerance'] ?? 1);
        $problems = [];
        if (isset($expect['count']) && $issues->count() !== (int) $expect['count']) {
            $problems[] = "số vấn đề {$issues->count()} ≠ {$expect['count']}";
        }
        foreach (['value_diff_sum' => 'value_diff', 'qty_diff_sum' => 'qty_diff'] as $key => $col) {
            if (isset($expect[$key])) {
                $actual = round((float) $issues->sum(fn ($i) => (float) $i->$col), 3);
                if (abs($actual - (float) $expect[$key]) > $tol) {
                    $problems[] = "{$col} hiện tại {$actual} ≠ {$expect[$key]}";
                }
            }
        }
        return [! $problems, $problems ? implode('; ', $problems) : null];
    }

    private function match(array $m)
    {
        if (! empty($m['policy'])) {
            return Issue::where('source', 'policy')->where('document_ref', $m['policy'])->get();
        }
        if (empty($m['rule']) || (empty($m['product_codes']) && empty($m['document']))) {
            return collect(); // bắt buộc điều kiện cụ thể, tránh gắn nhầm hàng loạt
        }
        $q = Issue::where('source', 'auto')->whereNotIn('status', Issue::CLOSED)->where('rule_code', $m['rule']);
        if (! empty($m['product_codes'])) {
            $q->whereIn('product_id', DB::table('products')->whereIn('code', $m['product_codes'])->pluck('id'));
        }
        if (! empty($m['warehouse_id'])) {
            $q->where('warehouse_id', (int) $m['warehouse_id']);
        }
        if (! empty($m['document'])) {
            $q->where('document_ref', $m['document']);
        }
        return $q->get();
    }
}
