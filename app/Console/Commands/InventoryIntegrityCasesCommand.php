<?php

namespace App\Console\Commands;

use App\Models\InventoryIntegrityIssue as Issue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gắn phương án xử lý (do kế toán/DEV lập, file JSON NGOÀI Git) vào vấn đề đã được audit phát hiện.
 * Không tạo vấn đề mới, không sửa số liệu phát hiện, không đụng dữ liệu kho/kế toán.
 *
 * File: [{"match": {"rule": "...", "product_codes": [..], "warehouse_id": 5, "document": "NK-..", "policy": "A3"},
 *         "case": {"title", "summary", "options": [{"name","description","impact"}], "to_confirm": [..], "source"},
 *         "category": "ACCOUNTING", "status": "PENDING_ACCOUNTING"}]
 */
class InventoryIntegrityCasesCommand extends Command
{
    protected $signature = 'inventory:integrity-cases {file : Đường dẫn file JSON} {--dry-run : Chỉ hiển thị vấn đề khớp}';

    protected $description = 'Gắn phương án xử lý (case) vào vấn đề của báo cáo Kiểm soát sai lệch tồn kho và kế toán';

    public function handle(): int
    {
        $cases = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($cases)) {
            $this->error('File JSON không hợp lệ.');
            return self::FAILURE;
        }
        $total = 0;
        foreach ($cases as $n => $c) {
            $issues = $this->match($c['match'] ?? []);
            $this->line(sprintf('#%d %s → %d vấn đề: %s', $n + 1, $c['case']['title'] ?? '', $issues->count(), $issues->map(fn ($i) => 'VD-' . $i->id)->implode(', ')));
            if ($issues->isEmpty()) {
                $this->warn('  Không khớp vấn đề nào (kiểm tra đã chạy audit / điều kiện match).');
                continue;
            }
            if ($this->option('dry-run')) {
                continue;
            }
            DB::transaction(function () use ($issues, $c) {
                foreach ($issues as $issue) {
                    $from = $issue->status;
                    $issue->case_info = array_merge($issue->case_info ?? [], $c['case'] ?? []);
                    if (! empty($c['category']) && in_array($c['category'], Issue::CATEGORIES, true)) {
                        $issue->category = $c['category'];
                    }
                    if (! empty($c['status']) && in_array($c['status'], Issue::MANUAL_STATUSES, true) && $issue->isOpen()) {
                        $issue->status = $c['status'];
                    }
                    $issue->save();
                    $issue->log('case', ['from_status' => $from, 'to_status' => $issue->status,
                        'note' => 'Gắn phương án: ' . ($c['case']['title'] ?? ''), 'payload' => ['source' => $c['case']['source'] ?? null]]);
                }
            });
            $total += $issues->count();
        }
        $this->info($this->option('dry-run') ? 'Dry-run: không ghi dữ liệu.' : "Đã gắn phương án cho {$total} vấn đề.");
        return self::SUCCESS;
    }

    private function match(array $m)
    {
        if (! empty($m['policy'])) {
            return Issue::where('source', 'policy')->where('document_ref', $m['policy'])->get();
        }
        $q = Issue::where('source', 'auto')->whereNotIn('status', Issue::CLOSED);
        if (empty($m['rule']) || (empty($m['product_codes']) && empty($m['document']))) {
            return collect(); // bắt buộc điều kiện cụ thể, tránh gắn nhầm hàng loạt
        }
        $q->where('rule_code', $m['rule']);
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
