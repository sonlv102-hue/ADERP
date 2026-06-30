<?php

namespace App\Console\Commands;

use App\Services\Reports\InventoryReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryDebugReportRowCommand extends Command
{
    protected $signature = 'inventory:debug-report-row
                            {--product= : Mã sản phẩm (ví dụ: "CAP 1.4")}
                            {--from=    : Ngày bắt đầu kỳ (YYYY-MM-DD)}
                            {--to=      : Ngày kết thúc kỳ (YYYY-MM-DD)}
                            {--warehouse= : warehouse_id (tùy chọn)}';

    protected $description = 'Debug row báo cáo tồn kho cho 1 sản phẩm — so sánh giá trị UI vs Excel';

    public function handle(): int
    {
        $productCode = $this->option('product');
        $dateFrom    = $this->option('from')      ?? now()->startOfYear()->toDateString();
        $dateTo      = $this->option('to')        ?? now()->toDateString();
        $warehouseId = $this->option('warehouse');

        if (!$productCode) {
            $this->error('Thiếu --product. Ví dụ: --product="CAP 1.4"');
            return self::FAILURE;
        }

        $this->line("=== Inventory Debug Report Row ===");
        $this->line("Product  : {$productCode}");
        $this->line("From     : {$dateFrom}");
        $this->line("To       : {$dateTo}");
        $this->line("Warehouse: " . ($warehouseId ?? 'tất cả'));
        $this->line('');

        // 1. Kiểm tra product tồn tại
        $product = DB::table('products')->where('code', $productCode)->whereNull('deleted_at')->first();
        if (!$product) {
            $this->error("Không tìm thấy sản phẩm mã: {$productCode}");
            return self::FAILURE;
        }
        $this->line("product_id : {$product->id}");
        $this->line("cost_price : " . number_format((float) $product->cost_price, 0, ',', '.') . " đ (giá hiện tại — KHÔNG dùng để tính báo cáo)");
        $this->line('');

        // 2. Kiểm tra inventory_balances (chỉ thông tin, không dùng cho báo cáo)
        $balances = DB::table('inventory_balances as ib')
            ->leftJoin('warehouses as w', 'w.id', '=', 'ib.warehouse_id')
            ->where('ib.product_id', $product->id)
            ->select(['ib.warehouse_id', 'w.name as warehouse_name', 'ib.qty_on_hand', 'ib.avg_cost', 'ib.value_on_hand'])
            ->get();

        if ($balances->isEmpty()) {
            $this->warn("[INFO] Không có inventory_balances rows (chưa init AVCO)");
        } else {
            $this->line("[INFO] inventory_balances hiện tại (tồn thực tế, không phải tồn đầu kỳ báo cáo):");
            foreach ($balances as $b) {
                $this->line("  Kho [{$b->warehouse_name}]: qty={$b->qty_on_hand}, avg_cost={$b->avg_cost}, value={$b->value_on_hand}");
            }
        }
        $this->line('');

        // 3. Tính giá trị từ InventoryReportService (cùng logic UI + Excel sau fix)
        $service = new InventoryReportService();
        $filters = [
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'warehouse_id' => $warehouseId,
        ];

        $row = $service->debugProductRow($productCode, $filters);

        if (!$row) {
            $this->warn("Sản phẩm {$productCode} không có data trong báo cáo (không có movements).");
            return self::SUCCESS;
        }

        // 4. Hiển thị kết quả từ service
        $this->info("=== Kết quả từ InventoryReportService (UI & Excel dùng chung) ===");
        $this->table(
            ['Trường', 'Giá trị'],
            [
                ['opening_qty',   $row['stock_begin']],
                ['opening_value', number_format($row['value_begin'],  2, '.', ',')],
                ['in_qty',        $row['stock_in']],
                ['in_value',      number_format($row['value_in'],     2, '.', ',')],
                ['last_receipt',  $row['last_in_date'] ?? '(chưa có)'],
                ['out_qty',       $row['stock_out']],
                ['out_value',     number_format($row['value_out'],    2, '.', ',')],
                ['closing_qty',   $row['stock_end']],
                ['closing_value', number_format($row['value_end'],    2, '.', ',')],
                ['source',        $row['source_description']],
            ]
        );

        // 5. Kiểm tra OLD Excel value (cost_price × qty) để show sự chênh lệch
        $oldExcelOpeningValue = $row['stock_begin'] * $row['cost_price'];
        if (abs($oldExcelOpeningValue - $row['value_begin']) > 1) {
            $this->newLine();
            $this->error("FAIL — Chênh lệch UI vs Excel (cũ trước khi sửa):");
            $this->line("  UI  (sm.amount sum)          : " . number_format($row['value_begin'],      2, '.', ','));
            $this->line("  Excel cũ (qty × cost_price)  : " . number_format($oldExcelOpeningValue,   2, '.', ','));
            $this->line("  Chênh lệch                   : " . number_format(abs($row['value_begin'] - $oldExcelOpeningValue), 2, '.', ','));
            $this->line('');
            $this->line("  Sau khi sửa, UI và Excel dùng cùng service → không còn chênh.");
        } else {
            $this->newLine();
            $this->info("OK — UI và Excel (sau fix) cho cùng kết quả (sai số < 1 đ).");
        }

        // 6. Kiểm tra stock_movements thực tế
        $this->newLine();
        $this->line("[DEBUG] Tất cả stock_movements cho {$productCode}" . ($warehouseId ? " tại kho #{$warehouseId}" : "") . ":");
        $movements = DB::table('stock_movements as sm')
            ->leftJoin('stock_entries as se', function ($j) {
                $j->on('sm.source_id', '=', 'se.id')->where('sm.source_type', '=', 'App\\Models\\StockEntry');
            })
            ->leftJoin('stock_exits as sx', function ($j) {
                $j->on('sm.source_id', '=', 'sx.id')->where('sm.source_type', '=', 'App\\Models\\StockExit');
            })
            ->leftJoin('warehouses as w', 'w.id', '=', 'sm.warehouse_id')
            ->where('sm.product_id', $product->id)
            ->when($warehouseId, fn ($q) => $q->where('sm.warehouse_id', $warehouseId))
            ->select([
                'sm.id',
                DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at)) as doc_date"),
                'sm.quantity',
                'sm.amount',
                'sm.status',
                'sm.source_type',
                'w.name as warehouse_name',
            ])
            ->orderBy(DB::raw("COALESCE(se.entry_date, sx.exit_date, DATE(sm.created_at))"))
            ->orderBy('sm.id')
            ->get();

        if ($movements->isEmpty()) {
            $this->warn("Không có stock_movements nào.");
        } else {
            $this->table(
                ['ID', 'Ngày', 'Kho', 'Qty', 'Amount', 'Status', 'Source'],
                $movements->map(fn ($m) => [
                    $m->id,
                    substr($m->doc_date ?? '', 0, 10),
                    $m->warehouse_name,
                    $m->quantity,
                    number_format((float)($m->amount ?? 0), 2, '.', ','),
                    $m->status ?? 'active',
                    class_basename($m->source_type ?? ''),
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
