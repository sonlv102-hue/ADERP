<?php

namespace App\Console\Commands;

use App\Services\AvcoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileInventoryBalancesCommand extends Command
{
    protected $signature = 'inventory:reconcile-balances
                            {--all-warehouses : Tất cả các kho}
                            {--warehouse= : Lọc theo warehouse_id}
                            {--dry-run : Chỉ hiển thị chênh lệch, không sửa}
                            {--apply : Sửa các dòng AVCO thấp hơn/thiếu bằng cách rebuild từ stock_entry_items}
                            {--threshold=0.01 : Ngưỡng chênh lệch coi là có vấn đề}';

    protected $description = 'Đối chiếu inventory_balances với SUM(stock_movements.quantity) theo product/warehouse.';

    public function handle(AvcoService $avco): int
    {
        $this->info('=== Đối chiếu inventory_balances vs stock_movements ===');
        $threshold = (float) $this->option('threshold');

        // SUM active movements per product+warehouse
        $movQuery = DB::table('stock_movements as m')
            ->where(fn ($q) => $q->whereNull('m.status')->orWhere('m.status', 'active'))
            ->whereNull('m.project_id')
            ->selectRaw('m.product_id, m.warehouse_id, SUM(m.quantity) as qty_from_movements')
            ->groupBy('m.product_id', 'm.warehouse_id');

        if ($this->option('warehouse')) {
            $movQuery->where('m.warehouse_id', $this->option('warehouse'));
        }
        if ($this->option('all-warehouses') === false && ! $this->option('warehouse')) {
            $this->error('Chỉ định --all-warehouses hoặc --warehouse=ID');
            return self::FAILURE;
        }

        $movements = $movQuery->get()->keyBy(fn ($r) => $r->product_id . '_' . $r->warehouse_id);

        // inventory_balances
        $balQuery = DB::table('inventory_balances as ib')
            ->join('products as p', 'p.id', '=', 'ib.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ib.warehouse_id')
            ->selectRaw('ib.product_id, ib.warehouse_id, ib.qty_on_hand, p.code as product_code, p.name as product_name, w.name as warehouse_name');

        if ($this->option('warehouse')) {
            $balQuery->where('ib.warehouse_id', $this->option('warehouse'));
        }

        $balances = $balQuery->get();

        $discrepancies = [];
        $negativeBalances = [];
        // Items có AVCO thấp hơn movements hoặc thiếu balance → có thể fix bằng rebuildFromEntries
        $fixableItems = [];

        foreach ($balances as $bal) {
            $key    = $bal->product_id . '_' . $bal->warehouse_id;
            $movQty = (float) ($movements[$key]->qty_from_movements ?? 0);
            $balQty = (float) $bal->qty_on_hand;
            $diff   = $balQty - $movQty;

            if ($balQty < 0) {
                $negativeBalances[] = $bal;
            }

            if (abs($diff) > $threshold) {
                $discrepancies[] = [
                    'P_ID'           => $bal->product_id,
                    'Sản phẩm'       => mb_substr($bal->product_name, 0, 25),
                    'Kho'            => mb_substr($bal->warehouse_name, 0, 15),
                    'Tồn AVCO'       => number_format($balQty, 3),
                    'SUM(movements)' => number_format($movQty, 3),
                    'Chênh lệch'     => number_format($diff, 3),
                    'Rủi ro'         => $diff < 0 ? 'AVCO thấp hơn movements' : 'AVCO cao hơn movements',
                ];
                // Chỉ đánh dấu fixable khi AVCO thấp hơn movements
                if ($diff < 0) {
                    $fixableItems[] = ['product_id' => $bal->product_id, 'warehouse_id' => $bal->warehouse_id];
                }
            }
        }

        // Movements không có balance (orphan positive)
        foreach ($movements as $key => $mov) {
            $exists = $balances->first(fn ($b) => $b->product_id === $mov->product_id && $b->warehouse_id === $mov->warehouse_id);
            if (! $exists && abs((float) $mov->qty_from_movements) > $threshold) {
                $discrepancies[] = [
                    'P_ID'           => $mov->product_id,
                    'Sản phẩm'       => '(chưa có balance)',
                    'Kho'            => 'W#' . $mov->warehouse_id,
                    'Tồn AVCO'       => 0,
                    'SUM(movements)' => number_format((float) $mov->qty_from_movements, 3),
                    'Chênh lệch'     => number_format(-(float) $mov->qty_from_movements, 3),
                    'Rủi ro'         => 'Thiếu inventory_balance',
                ];
                $fixableItems[] = ['product_id' => $mov->product_id, 'warehouse_id' => $mov->warehouse_id];
            }
        }

        if (empty($discrepancies) && empty($negativeBalances)) {
            $this->info("✓ Tất cả {$balances->count()} dòng inventory_balances khớp với stock_movements.");
            return self::SUCCESS;
        }

        if (! empty($discrepancies)) {
            $this->warn(count($discrepancies) . ' dòng có chênh lệch (ngưỡng ' . $threshold . '):');
            $this->table(array_keys($discrepancies[0]), $discrepancies);
        }

        if (! empty($negativeBalances)) {
            $this->error(count($negativeBalances) . ' balance âm:');
            foreach ($negativeBalances as $b) {
                $this->line("  Product #{$b->product_id} ({$b->product_name}) / Warehouse {$b->warehouse_name}: qty_on_hand = {$b->qty_on_hand}");
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Chế độ --dry-run: không thay đổi dữ liệu.');
            $this->line(count($fixableItems) . ' dòng có thể fix với --apply (AVCO thấp/thiếu).');
            $highRisk = count($discrepancies) - count($fixableItems);
            if ($highRisk > 0) {
                $this->warn("{$highRisk} dòng 'AVCO cao hơn movements' cần kiểm tra thủ công — không tự fix.");
            }
            return self::FAILURE;
        }

        if ($this->option('apply')) {
            return $this->applyFix($avco, $fixableItems, count($discrepancies) - count($fixableItems));
        }

        $this->newLine();
        $this->line('Lưu ý: inventory_balances là ground truth sau AVCO. Chênh lệch có thể do:');
        $this->line('  1. Orphan movements chưa được void (chạy audit-orphan-movements)');
        $this->line('  2. Movement ngoài AVCO (opening balance, adjustment)');
        $this->line('  3. Voided movements chưa cập nhật balance');
        $this->line('Dùng --apply để tự sửa các dòng AVCO thấp/thiếu từ lịch sử nhập kho.');

        return self::FAILURE;
    }

    private function applyFix(AvcoService $avco, array $fixableItems, int $highRiskCount): int
    {
        $this->newLine();
        $this->info('=== Áp dụng --apply: rebuild từ stock_entry_items ===');
        $this->warn("Ghi chú: 'AVCO cao hơn movements' ({$highRiskCount} dòng) bị BỎ QUA — cần kiểm tra thủ công.");

        if (empty($fixableItems)) {
            $this->info('Không có dòng nào cần fix.');
            return self::SUCCESS;
        }

        $fixed  = 0;
        $noData = 0;

        foreach ($fixableItems as $item) {
            try {
                $balance = $avco->rebuildFromEntries($item['product_id'], $item['warehouse_id']);
                if ($balance) {
                    $this->line(sprintf(
                        '  ✓ P#%d / W#%d: qty=%.3f, avg_cost=%.2f, value=%.2f',
                        $item['product_id'],
                        $item['warehouse_id'],
                        $balance->qty_on_hand,
                        $balance->avg_cost,
                        $balance->value_on_hand,
                    ));
                    $fixed++;
                } else {
                    $this->warn(sprintf(
                        '  ⚠ P#%d / W#%d: không tìm thấy entry data có giá → bỏ qua (cần nhập tồn kho đầu kỳ thủ công)',
                        $item['product_id'],
                        $item['warehouse_id'],
                    ));
                    $noData++;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ P#{$item['product_id']} / W#{$item['warehouse_id']}: " . $e->getMessage());
                $noData++;
            }
        }

        $this->newLine();
        $this->info("Hoàn thành: {$fixed} đã rebuild, {$noData} bỏ qua.");
        if ($noData > 0) {
            $this->warn('Các dòng bỏ qua cần nhập tồn kho đầu kỳ thủ công (Kho → Tồn kho đầu kỳ).');
        }

        return $fixed > 0 ? self::SUCCESS : self::FAILURE;
    }
}
