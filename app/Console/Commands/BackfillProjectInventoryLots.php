<?php

namespace App\Console\Commands;

use App\Models\ProjectInventoryLot;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockEntry;
use App\Models\StockEntryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillProjectInventoryLots extends Command
{
    protected $signature = 'project-lots:backfill {--dry-run : Preview without writing} {--entry= : Only process a specific stock entry code}';
    protected $description = 'Tạo project_inventory_lots cho các phiếu nhập kho đã confirmed trước Phase G (2026-06-13)';

    public function handle(): int
    {
        $dryRun    = $this->option('dry-run');
        $entryCode = $this->option('entry');

        $query = StockEntry::where('status', 'confirmed')
            ->whereNotNull('purchase_order_id');

        if ($entryCode) {
            $query->where('code', $entryCode);
        }

        $entries = $query->with(['items', 'purchaseOrder'])->get();

        $created = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $po = $entry->purchaseOrder;

            foreach ($entry->items as $item) {
                // Skip nếu lot đã tồn tại cho item này
                $exists = ProjectInventoryLot::where('stock_entry_item_id', $item->id)->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Resolve project_id
                $projectId = $item->project_id;

                if (!$projectId && $item->purchase_order_item_id) {
                    $poItem    = PurchaseOrderItem::find($item->purchase_order_item_id);
                    $projectId = $poItem?->project_id;
                }

                if (!$projectId) {
                    $projectId = $po?->project_id;
                }

                if (!$projectId) {
                    $skipped++;
                    continue;
                }

                $unitCost = (float) ($item->unit_cost ?? $item->unit_price ?? 0);

                $this->line(sprintf(
                    '%s  Entry %s · item %d · product_id %d · project_id %d · qty %s · unit_cost %s',
                    $dryRun ? '[DRY]' : '[CREATE]',
                    $entry->code,
                    $item->id,
                    $item->product_id,
                    $projectId,
                    $item->quantity,
                    number_format($unitCost)
                ));

                if (!$dryRun) {
                    DB::transaction(function () use ($entry, $item, $projectId, $unitCost) {
                        ProjectInventoryLot::create([
                            'project_id'             => $projectId,
                            'product_id'             => $item->product_id,
                            'warehouse_id'           => $entry->warehouse_id,
                            'stock_entry_id'         => $entry->id,
                            'stock_entry_item_id'    => $item->id,
                            'purchase_order_id'      => $entry->purchase_order_id,
                            'purchase_order_item_id' => $item->purchase_order_item_id,
                            'received_qty'           => $item->quantity,
                            'issued_qty'             => 0,
                            'unit_cost'              => $unitCost,
                            'received_at'            => $entry->entry_date ?? now(),
                            'status'                 => 'active',
                        ]);

                        // Also update item.project_id if null
                        if (!$item->project_id) {
                            $item->update(['project_id' => $projectId, 'unit_cost' => $unitCost]);
                        }
                    });
                }

                $created++;
            }
        }

        $verb = $dryRun ? 'Sẽ tạo' : 'Đã tạo';
        $this->info("{$verb} {$created} lot(s). Bỏ qua (đã có hoặc không có project_id): {$skipped}.");

        return self::SUCCESS;
    }
}
