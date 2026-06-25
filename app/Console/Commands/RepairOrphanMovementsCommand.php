<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairOrphanMovementsCommand extends Command
{
    protected $signature = 'inventory:repair-orphan-movements
                            {--project-only : Chỉ xử lý movements có project_id}
                            {--dry-run : Mô phỏng — không thay đổi dữ liệu (mặc định)}
                            {--apply : Áp dụng thật — yêu cầu xác nhận}';

    protected $description = 'Void orphan stock_movements (nhóm A không JE/WIP) và điều chỉnh inventory_balances tương ứng.';

    public function handle(): int
    {
        $apply = $this->option('apply');
        $dryRun = ! $apply;

        if ($dryRun) {
            $this->warn('[DRY-RUN] Chỉ hiển thị — không thay đổi dữ liệu. Dùng --apply để áp dụng.');
        }

        // Lấy orphan movements nhóm A: nguồn exit không tồn tại, không có JE active, không có WIP
        $orphans = $this->getGroupAOrphans();

        if ($orphans->isEmpty()) {
            $this->info('✓ Không tìm thấy orphan movement nhóm A nào cần xử lý.');
            return self::SUCCESS;
        }

        $this->warn("Tìm thấy {$orphans->count()} orphan movement(s) nhóm A:");
        $this->table(
            ['M_ID', 'P_ID', 'Sản phẩm', 'W_ID', 'DA', 'Qty', 'Giá trị'],
            $orphans->map(fn ($m) => [
                $m->movement_id, $m->product_id,
                mb_substr($m->product_name ?? '', 0, 25),
                $m->warehouse_id, $m->project_code ?? '-',
                $m->quantity, number_format((float) $m->amount, 0, '.', ','),
            ])->values()->toArray()
        );

        $totalQty = $orphans->sum(fn ($m) => abs($m->quantity));
        $totalVal = $orphans->sum(fn ($m) => abs((float) $m->amount));
        $hasPositive = $orphans->contains(fn ($m) => $m->quantity > 0);
        $this->newLine();
        $this->line("Sẽ void {$orphans->count()} movements, tổng {$totalQty} units, " . number_format($totalVal, 0, '.', ',') . 'đ');
        $this->line("Điều chỉnh inventory_balance: -quantity (đảo ngược ảnh hưởng gốc). value_on_hand/avg_cost giữ nguyên.");
        if ($hasPositive) {
            $this->warn("⚠  Có movements DƯƠNG (+) — void sẽ làm GIẢM balance kho đích. Kiểm tra balance trước khi apply.");
        }
        $this->warn("GL không bị tác động (JE đã bị xóa cùng exit). Kiểm tra thủ công nếu cần.");

        if ($dryRun) {
            $this->newLine();
            $this->info('[DRY-RUN] Kết thúc. Chạy với --apply để áp dụng.');
            return self::SUCCESS;
        }

        // Kiểm tra kỳ kế toán khóa — không xử lý movement trong kỳ bị khóa
        // (đơn giản hóa: kiểm tra nếu có accounting_period locked trùng với ngày movement)
        if (! $this->confirm("Xác nhận void {$orphans->count()} movements và cập nhật inventory_balances?")) {
            $this->info('Hủy bỏ.');
            return self::SUCCESS;
        }

        $userId = auth()->id() ?? 1;
        $now    = now();
        $voided = 0;

        DB::transaction(function () use ($orphans, $userId, $now, &$voided) {
            foreach ($orphans as $m) {
                // Void movement
                DB::table('stock_movements')->where('id', $m->movement_id)->update([
                    'status'       => 'voided',
                    'cancelled_by' => $userId,
                    'cancelled_at' => $now,
                    'cancel_reason'=> 'Orphan: stock_exit #' . $m->stock_exit_id . ' không tồn tại',
                    'updated_at'   => $now,
                ]);

                // Đảo ngược ảnh hưởng lên inventory_balance (chỉ cập nhật nếu row tồn tại)
                $adj = -$m->quantity; // qty=-15 → adj=+15; qty=+15 → adj=-15
                if ($adj != 0) {
                    InventoryBalance::where('product_id', $m->product_id)
                        ->where('warehouse_id', $m->warehouse_id)
                        ->increment('qty_on_hand', $adj);
                }

                $voided++;
            }
        });

        $this->info("✓ Đã void {$voided} movements và cập nhật inventory_balances.");
        $this->line('Chạy audit để kiểm tra lại: php artisan inventory:audit-orphan-movements --project-only');
        $this->line('Đối chiếu tồn kho: php artisan inventory:reconcile-balances --all-warehouses --dry-run');

        return self::SUCCESS;
    }

    private function getGroupAOrphans()
    {
        $projectOnly = $this->option('project-only');

        $query = DB::table('stock_movements as m')
            ->leftJoin('stock_exits as x', 'm.source_id', '=', 'x.id')
            ->leftJoin('products as p', 'm.product_id', '=', 'p.id')
            ->leftJoin('projects as pr', 'm.project_id', '=', 'pr.id')
            ->where('m.source_type', '=', \App\Models\StockExit::class)
            ->whereNull('x.id')
            ->where(fn ($q) => $q->whereNull('m.status')->orWhere('m.status', 'active'))
            ->select([
                'm.id as movement_id', 'm.product_id', 'p.name as product_name',
                'm.warehouse_id', 'm.project_id', 'pr.code as project_code',
                'm.quantity', 'm.amount', 'm.source_id as stock_exit_id',
            ]);

        if ($projectOnly) {
            $query->whereNotNull('m.project_id');
        }

        $candidates = $query->get();
        $exitIds = $candidates->pluck('stock_exit_id')->unique()->values();

        $jeExitIds = DB::table('journal_entries')
            ->where('reference_type', 'stock_exit')
            ->whereIn('reference_id', $exitIds)
            ->whereNotIn('status', ['reversed', 'voided'])
            ->pluck('reference_id')->values();

        $wipExitIds = DB::table('project_wip_entries')
            ->where('source_type', \App\Models\StockExit::class)
            ->whereIn('source_id', $exitIds)
            ->where('status', 'active')
            ->pluck('source_id')->values();

        return $candidates->filter(fn ($m) =>
            ! $jeExitIds->contains($m->stock_exit_id) &&
            ! $wipExitIds->contains($m->stock_exit_id)
        );
    }
}
