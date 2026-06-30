<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\JournalEntry;
use App\Models\OrderItem;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditStockExitConfirmChainCommand extends Command
{
    protected $signature = 'stock-exits:audit-confirm-chain
                            {--code= : Mã phiếu xuất cần kiểm tra (XK-xxx)}
                            {--all-confirmed : Kiểm tra tất cả phiếu đã confirmed}';

    protected $description = 'Kiểm tra 7-bước chain sau khi confirm StockExit cho dự án.';

    public function handle(): int
    {
        if ($this->option('all-confirmed')) {
            return $this->auditAll();
        }

        $code = $this->option('code');
        if (! $code) {
            $this->error('Cần --code=XK-xxx hoặc --all-confirmed');
            return 1;
        }

        $exit = StockExit::with(['items.product', 'order.items', 'project', 'warehouse'])
            ->where('code', $code)->first();

        if (! $exit) {
            $this->error("Không tìm thấy phiếu xuất: {$code}");
            return 1;
        }

        return $this->auditExit($exit) ? 0 : 1;
    }

    private function auditAll(): int
    {
        $exits = StockExit::where('status', 'confirmed')
            ->where('issue_purpose', 'project_cost')
            ->with(['items.product', 'order.items', 'project', 'warehouse'])
            ->orderByDesc('id')
            ->get();

        if ($exits->isEmpty()) {
            $this->info('Không có phiếu project_cost confirmed nào.');
            return 0;
        }

        $failCount = 0;
        foreach ($exits as $exit) {
            if (! $this->auditExit($exit, compact: true)) {
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("Kết quả: {$exits->count()} phiếu | Lỗi: {$failCount}");
        return $failCount > 0 ? 1 : 0;
    }

    private function auditExit(StockExit $exit, bool $compact = false): bool
    {
        $errors   = [];
        $warnings = [];

        if (! $compact) {
            $this->newLine();
            $this->info("══════════════════════════════════════════════════");
            $this->info("AUDIT: {$exit->code}  [{$exit->issue_purpose}]  Status={$exit->status?->value}");
            $this->info("══════════════════════════════════════════════════");
            $this->line("  Kho: {$exit->warehouse?->name}  |  Dự án: {$exit->project?->code}  |  Đơn hàng: {$exit->order?->code}");
        }

        $isProjectCost = $exit->issue_purpose === 'project_cost';

        // ── Bước 1+2: stock_movements ──────────────────────────────────
        foreach ($exit->items as $item) {
            $movement = StockMovement::where('source_type', StockExit::class)
                ->where('source_id', $exit->id)
                ->where('product_id', $item->product_id)
                ->where('type', 'out')
                ->first();

            if (! $movement) {
                $errors[] = "[{$item->product?->code}] Thiếu stock_movement OUT.";
            } elseif ($movement->warehouse_id !== $exit->warehouse_id) {
                $errors[] = "[{$item->product?->code}] stock_movement warehouse_id={$movement->warehouse_id} ≠ exit warehouse_id={$exit->warehouse_id}.";
            }

            // Bước 1: inventory_balances
            if ($isProjectCost) {
                $balance = InventoryBalance::where('product_id', $item->product_id)
                    ->where('warehouse_id', $exit->warehouse_id)
                    ->first();
                if (! $balance) {
                    $warnings[] = "[{$item->product?->code}] inventory_balances chưa có row — có thể chưa init AVCO.";
                }
            }
        }

        // ── Bước 3+4: JE + WIP (chỉ với project_cost) ─────────────────
        if ($isProjectCost) {
            $je = JournalEntry::where('reference_type', 'stock_exit')
                ->where('reference_id', $exit->id)
                ->whereIn('status', ['draft', 'posted'])
                ->first();

            if (! $je) {
                $errors[] = 'Thiếu JournalEntry (Dr154/Cr156) cho exit này.';
            } else {
                $debitLine = $je->lines()->where('debit', '>', 0)->first();
                if (! $debitLine || ! str_starts_with($debitLine->account_code, '154')) {
                    $warnings[] = "JE {$je->code}: dòng Nợ không phải TK 154 (thực tế: {$debitLine?->account_code}).";
                }

                $wipEntries = ProjectWipEntry::where('source_type', StockExit::class)
                    ->where('source_id', $exit->id)
                    ->whereIn('status', ['active'])
                    ->count();

                if ($wipEntries === 0) {
                    $errors[] = "Có JE nhưng không có project_wip_entries active.";
                }
            }
        }

        // ── Bước 5+6: delivered_quantity vs confirmed qty ──────────────
        if ($exit->order_id) {
            $order = $exit->order;
            if (! $order) {
                $warnings[] = "order_id={$exit->order_id} nhưng Order không tồn tại.";
            } else {
                $confirmedQty = StockExitItem::select('order_item_id', DB::raw('SUM(quantity) as qty'))
                    ->join('stock_exits', 'stock_exits.id', '=', 'stock_exit_items.stock_exit_id')
                    ->whereIn('order_item_id', $order->items->pluck('id'))
                    ->where('stock_exits.status', 'confirmed')
                    ->groupBy('order_item_id')
                    ->pluck('qty', 'order_item_id')
                    ->map(fn ($v) => (float) $v);

                foreach ($order->items->whereNotNull('product_id') as $oi) {
                    $confirmed   = $confirmedQty[$oi->id] ?? 0.0;
                    $fieldStored = (float) $oi->delivered_quantity;

                    if (abs($confirmed - $fieldStored) > 0.001) {
                        $errors[] = "order_item #{$oi->id} ({$oi->name}): delivered_quantity={$fieldStored} ≠ confirmed_exit_qty={$confirmed}. Cần resync.";
                    }
                }
            }
        } elseif ($isProjectCost) {
            $warnings[] = 'Phiếu project_cost không có order_id — syncDelivery() bị bỏ qua.';
        }

        // ── Output ─────────────────────────────────────────────────────
        $ok = empty($errors);

        if ($compact) {
            $icon = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $errStr = $ok ? '' : ' — ' . implode('; ', $errors);
            $this->line("  {$icon} {$exit->code}{$errStr}");
        } else {
            if ($errors) {
                foreach ($errors as $e) {
                    $this->error("  ✗ {$e}");
                }
            }
            if ($warnings) {
                foreach ($warnings as $w) {
                    $this->warn("  ⚠ {$w}");
                }
            }
            if ($ok && ! $warnings) {
                $this->info("  <fg=green>✓ Toàn bộ chain OK.</>");
            } elseif ($ok) {
                $this->info("  <fg=yellow>✓ Chain OK nhưng có cảnh báo.</>");
            }
        }

        return $ok;
    }
}
