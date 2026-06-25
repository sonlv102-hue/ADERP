<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditOrphanMovementsCommand extends Command
{
    protected $signature = 'inventory:audit-orphan-movements
                            {--project-only : Chỉ kiểm tra movements có project_id}
                            {--warehouse= : Lọc theo warehouse_id}';

    protected $description = 'Kiểm tra stock_movements không có chứng từ nguồn hợp lệ (orphan exits).';

    public function handle(): int
    {
        $this->info('=== Audit Orphan Stock Movements ===');

        $query = DB::table('stock_movements as m')
            ->leftJoin('stock_exits as x', 'm.source_id', '=', 'x.id')
            ->leftJoin('products as p', 'm.product_id', '=', 'p.id')
            ->leftJoin('warehouses as w', 'm.warehouse_id', '=', 'w.id')
            ->leftJoin('projects as pr', 'm.project_id', '=', 'pr.id')
            ->leftJoin('inventory_balances as ib', function ($j) {
                $j->on('ib.product_id', '=', 'm.product_id')
                  ->on('ib.warehouse_id', '=', 'm.warehouse_id');
            })
            ->where('m.source_type', '=', \App\Models\StockExit::class)
            ->whereNull('x.id')
            ->where(fn ($q) => $q->whereNull('m.status')->orWhere('m.status', 'active'))
            ->select([
                'm.id as movement_id',
                'm.product_id',
                'p.name as product_name',
                'm.warehouse_id',
                'm.project_id',
                'pr.code as project_code',
                'm.quantity',
                'm.amount',
                DB::raw("m.created_at::date as movement_date"),
                'm.source_id as stock_exit_id',
                'ib.qty_on_hand as current_balance',
            ]);

        if ($this->option('project-only')) {
            $query->whereNotNull('m.project_id');
        }
        if ($this->option('warehouse')) {
            $query->where('m.warehouse_id', $this->option('warehouse'));
        }

        $orphans = $query->get();

        if ($orphans->isEmpty()) {
            $this->info('✓ Không tìm thấy orphan movements.');
            return self::SUCCESS;
        }

        // Enrich với JE + WIP linkage
        $exitIds = $orphans->pluck('stock_exit_id')->unique()->values();

        $jeByExitId = DB::table('journal_entries')
            ->where('reference_type', 'stock_exit')
            ->whereIn('reference_id', $exitIds)
            ->whereNotIn('status', ['reversed', 'voided'])
            ->pluck('id', 'reference_id');

        $wipByExitId = DB::table('project_wip_entries')
            ->where('source_type', \App\Models\StockExit::class)
            ->whereIn('source_id', $exitIds)
            ->where('status', 'active')
            ->pluck('id', 'source_id');

        $enriched = $orphans->map(function ($m) use ($jeByExitId, $wipByExitId) {
            $jeId  = $jeByExitId[$m->stock_exit_id] ?? null;
            $wipId = $wipByExitId[$m->stock_exit_id] ?? null;

            if ($wipId) {
                $m->group = 'C';
                $m->suggested_action = 'Xử lý đồng bộ movement+JE+WIP';
            } elseif ($jeId) {
                $m->group = 'B';
                $m->suggested_action = 'Kiểm tra JE trước khi hủy';
            } else {
                $m->group = 'A';
                $m->suggested_action = 'Có thể void + điều chỉnh tồn';
            }
            $m->journal_entry_id = $jeId;
            $m->wip_entry_id     = $wipId;
            return $m;
        });

        $groupA = $enriched->where('group', 'A');
        $groupB = $enriched->where('group', 'B');
        $groupC = $enriched->where('group', 'C');

        $this->warn("Tìm thấy {$enriched->count()} orphan movement(s).");
        $this->line("  Nhóm A (không JE/WIP — có thể void): {$groupA->count()}");
        $this->line("  Nhóm B (có JE, không WIP — cần kiểm tra): {$groupB->count()}");
        $this->line("  Nhóm C (có WIP — phải xử lý đồng bộ): {$groupC->count()}");
        $this->newLine();

        $rows = $enriched->map(fn ($m) => [
            $m->movement_id,
            $m->product_id,
            mb_substr($m->product_name ?? '', 0, 25),
            $m->project_code ?? '-',
            $m->quantity,
            number_format((float) $m->amount, 0, '.', ','),
            $m->movement_date,
            $m->stock_exit_id,
            $m->journal_entry_id ?? '-',
            $m->wip_entry_id ?? '-',
            $m->group,
            $m->current_balance ?? 0,
        ])->values()->toArray();

        $this->table(
            ['M_ID', 'P_ID', 'Sản phẩm', 'DA', 'Qty', 'Giá trị', 'Ngày', 'Exit_ID', 'JE_ID', 'WIP_ID', 'Nhóm', 'Tồn hiện tại'],
            $rows
        );

        $totalQty = $groupA->sum(fn ($m) => abs($m->quantity));
        $totalVal = $groupA->sum(fn ($m) => abs((float) $m->amount));
        $this->newLine();
        $this->line("Nhóm A: tổng {$totalQty} units, " . number_format($totalVal, 0, '.', ',') . 'đ — chạy repair để void an toàn');
        if ($groupB->count()) $this->warn("Nhóm B: {$groupB->count()} — kiểm tra JE thủ công trước");
        if ($groupC->count()) $this->warn("Nhóm C: {$groupC->count()} — không repair tự động");

        $this->newLine();
        $this->line('Tiếp theo: php artisan inventory:repair-orphan-movements --project-only --dry-run');

        return self::FAILURE;
    }
}
