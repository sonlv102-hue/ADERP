<?php

namespace App\Services\Inventory;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trình bày kết quả InventoryFullAuditService cho `inventory:audit --scope=full` (READ-ONLY):
 * lọc theo sản phẩm/kho/dự án/chứng từ/nhóm sai lệch, xuất CSV (UTF-8 BOM, mở được bằng Excel),
 * tùy chọn so sánh "trước repair" với một database baseline (--baseline-db).
 * Ghi chú khảo sát thủ công nằm ở file JSON ngoài Git (--notes), không hardcode dữ liệu production.
 */
class InventoryFullAuditReporter
{
    private const COLUMNS = [
        'product_id' => 'Product ID', 'product_code' => 'SKU', 'product_name' => 'Tên sản phẩm', 'unit' => 'ĐVT',
        'inactive' => 'Ngừng HĐ', 'warehouse_id' => 'Warehouse ID', 'warehouse_name' => 'Kho',
        'in_qty' => 'Tổng nhập', 'out_qty' => 'Tổng xuất', 'mv_qty' => 'Tồn sổ kho (movement)',
        'lot_qty' => 'Tồn lô dự án', 'lot_issued' => 'Lô đã xuất', 'alloc_qty' => 'Allocation hiệu lực',
        'avco_qty' => 'Tồn AVCO kho chung', 'available_qty' => 'Tồn khả dụng kho chung',
        'avco_value' => 'Giá trị AVCO', 'avg_cost' => 'Giá bình quân', 'mv_value' => 'Giá trị sổ kho',
        'lot_value' => 'Giá trị lô', 'expected_avco_qty' => 'AVCO kỳ vọng (SL)', 'expected_avco_value' => 'AVCO kỳ vọng (GT)',
        'qty_diff' => 'Chênh SL', 'value_diff' => 'Chênh GT', 'status_text' => 'Trạng thái', 'cause' => 'Nguyên nhân',
        'action' => 'Phương án', 'documents' => 'Chứng từ liên quan',
        'before_status' => 'Trạng thái trước repair', 'before_avco_qty' => 'AVCO SL trước', 'before_avco_value' => 'AVCO GT trước',
    ];

    public function __construct(private InventoryFullAuditService $audit) {}

    public function run(Command $cmd): int
    {
        $filters = $this->filters($cmd);
        $notes = $this->notes($cmd->option('notes'));
        $rows = $this->audit->run($filters, $notes);
        $before = $cmd->option('baseline-db') ? $this->baseline($cmd->option('baseline-db'), $filters) : collect();

        $rows->each(function ($r) use ($before, $notes) {
            $key = $r->product_id . '-' . $r->warehouse_id;
            $r->status_text = implode(',', $r->statuses);
            $r->action = $notes[$key]['action'] ?? $this->defaultAction($r->statuses);
            $b = $before[$key] ?? null;
            $r->before_status = $b ? implode(',', $b->statuses) : '';
            $r->before_avco_qty = $b?->avco_qty;
            $r->before_avco_value = $b?->avco_value;
        });

        $this->summary($cmd, $rows);
        if ($path = $cmd->option('export')) {
            $this->csv($path, $rows);
            $cmd->info("Đã xuất CSV: {$path}");
        }
        return $rows->contains(fn ($r) => array_diff($r->statuses, ['VALID', 'EXPECTED_DIFFERENCE', 'LEGACY_DATA'])) ? Command::FAILURE : Command::SUCCESS;
    }

    private function filters(Command $cmd): array
    {
        $products = null;
        if ($p = $cmd->option('product')) {
            $products = collect(explode(',', $p))->map(fn ($v) => is_numeric(trim($v)) ? (int) $v
                : (int) DB::table('products')->where('code', trim($v))->value('id'))->filter()->values()->all() ?: [-1];
        }
        $project = $cmd->option('project');
        if ($project && ! is_numeric($project)) {
            $project = DB::table('projects')->where('code', $project)->value('id') ?? -1;
        }
        return ['product' => $products, 'warehouse' => $cmd->option('warehouse'), 'project' => $project,
                'document' => $cmd->option('document'), 'status' => $cmd->option('status')];
    }

    private function notes(?string $path): array
    {
        if (! $path) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function baseline(string $database, array $filters): Collection
    {
        $default = DB::getDefaultConnection();
        config(["database.connections.audit_baseline" => array_merge(config("database.connections.{$default}"), ['database' => $database])]);
        DB::purge('audit_baseline');
        DB::setDefaultConnection('audit_baseline');
        try {
            return app(InventoryFullAuditService::class)->run($filters)->keyBy(fn ($r) => $r->product_id . '-' . $r->warehouse_id);
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    private function defaultAction(array $statuses): string
    {
        return match (true) {
            in_array('NEGATIVE_STOCK', $statuses, true) => 'PENDING_DECISION: tồn âm — không tự cân bằng, cần xác định chứng từ nhập thực tế',
            (bool) array_intersect($statuses, ['PHANTOM_STOCK', 'QUANTITY_MISMATCH', 'MISSING_BALANCE', 'VALUE_MISMATCH']) => 'Repair nếu đủ bằng chứng (manifest), ngược lại UNRESOLVED',
            (bool) array_intersect($statuses, ['ACCOUNTING_MISMATCH', 'ALLOCATION_MISMATCH', 'DUPLICATE_TRANSACTION', 'AVAILABILITY_MISMATCH']) => 'Rà soát chứng từ/JE liên quan',
            default => 'Không cần xử lý',
        };
    }

    private function summary(Command $cmd, Collection $rows): void
    {
        $pw = $rows->whereNotNull('warehouse_id');
        $cmd->info(sprintf('Đã kiểm tra: %d sản phẩm · %d kho · %d dòng sản phẩm×kho (+%d sản phẩm không phát sinh)',
            $rows->pluck('product_id')->unique()->count(), $pw->pluck('warehouse_id')->unique()->count(),
            $pw->count(), $rows->whereNull('warehouse_id')->count()));
        $counts = [];
        foreach ($rows as $r) {
            foreach ($r->statuses as $s) {
                $counts[$s] = ($counts[$s] ?? 0) + 1;
            }
        }
        $order = array_flip(InventoryFullAuditService::STATUSES);
        uksort($counts, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        $cmd->table(['Trạng thái', 'Số dòng'], collect($counts)->map(fn ($n, $s) => [$s, $n])->values()->all());

        $bad = $rows->reject(fn ($r) => ! array_diff($r->statuses, ['VALID', 'EXPECTED_DIFFERENCE', 'LEGACY_DATA']));
        if ($bad->isNotEmpty() || $rows->count() <= 20) {
            $show = $rows->count() <= 20 ? $rows : $bad;
            $cmd->table(['SP', 'SKU', 'Kho', 'Sổ kho', 'Lô', 'AVCO', 'Khả dụng', 'GT AVCO', 'GT kỳ vọng', 'Trạng thái'],
                $show->map(fn ($r) => [$r->product_id, $r->product_code, $r->warehouse_id, $r->mv_qty, $r->lot_qty, $r->avco_qty ?? 'null',
                    $r->available_qty, $r->avco_value ?? '-', $r->expected_avco_value, $r->status_text])->all());
        }
    }

    private function csv(string $path, Collection $rows): void
    {
        @mkdir(dirname($path), 0777, true);
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_values(self::COLUMNS));
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($k) => $this->cell($r->$k ?? null), array_keys(self::COLUMNS)));
        }
        fclose($fh);
    }

    /** Chống CSV/Excel formula injection cho cột chữ (tên SP, nguyên nhân). */
    private function cell(mixed $v): string
    {
        $v = (string) ($v ?? '');
        return preg_match('/^[=+\-@]/', $v) && ! is_numeric($v) ? "'" . $v : $v;
    }
}
