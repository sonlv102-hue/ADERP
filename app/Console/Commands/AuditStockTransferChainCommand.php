<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditStockTransferChainCommand extends Command
{
    protected $signature = 'stock-transfers:audit-chain
                            {--code= : Mã phiếu chuyển kho (CK-xxx)}
                            {--all-confirmed : Kiểm tra tất cả phiếu đã confirmed}';

    protected $description = 'Kiểm tra AVCO chain sau khi confirm StockTransfer: movement out+in, inventory_balances.';

    public function handle(): int
    {
        if ($this->option('all-confirmed')) {
            return $this->auditAll();
        }

        $code = $this->option('code');
        if (!$code) {
            $this->error('Cần --code=CK-xxx hoặc --all-confirmed');
            return self::FAILURE;
        }

        $transfer = StockTransfer::with(['items.product', 'fromWarehouse', 'toWarehouse'])
            ->where('code', $code)->first();

        if (!$transfer) {
            $this->error("Không tìm thấy phiếu chuyển kho: {$code}");
            return self::FAILURE;
        }

        return $this->auditOne($transfer) ? self::SUCCESS : self::FAILURE;
    }

    private function auditAll(): int
    {
        $transfers = StockTransfer::with(['items.product', 'fromWarehouse', 'toWarehouse'])
            ->where('status', 'confirmed')->get();

        $this->info("Kiểm tra {$transfers->count()} phiếu confirmed...");
        $failCount = 0;

        foreach ($transfers as $transfer) {
            if (!$this->auditOne($transfer)) {
                $failCount++;
            }
        }

        $this->newLine();
        if ($failCount === 0) {
            $this->info("✓ Tất cả phiếu OK.");
        } else {
            $this->error("✗ {$failCount} phiếu có vấn đề.");
        }

        return $failCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function auditOne(StockTransfer $transfer): bool
    {
        $this->line("=== {$transfer->code} [{$transfer->fromWarehouse->name} → {$transfer->toWarehouse->name}] ===");
        $allOk = true;

        foreach ($transfer->items as $item) {
            $productName = $item->product->name ?? "ID={$item->product_id}";
            $this->line("  Sản phẩm: {$productName} (qty={$item->quantity})");

            // 1. Kiểm tra OUT movement tại kho nguồn
            $outMov = StockMovement::where('source_type', StockTransfer::class)
                ->where('source_id', $transfer->id)
                ->where('product_id', $item->product_id)
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('type', 'out')
                ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'active'); })
                ->first();

            if (!$outMov) {
                $this->error("    ✗ Thiếu movement OUT tại kho nguồn");
                $allOk = false;
            } elseif ($outMov->unit_cost <= 0 || $outMov->amount >= 0) {
                $this->warn("    ⚠ Movement OUT thiếu unit_cost/amount (transfer cũ trước khi có AVCO fix)");
                $allOk = false;
            } else {
                $this->line("    ✓ OUT movement: qty={$outMov->quantity}, unit_cost={$outMov->unit_cost}, amount={$outMov->amount}");
            }

            // 2. Kiểm tra IN movement tại kho đích
            $inMov = StockMovement::where('source_type', StockTransfer::class)
                ->where('source_id', $transfer->id)
                ->where('product_id', $item->product_id)
                ->where('warehouse_id', $transfer->to_warehouse_id)
                ->where('type', 'in')
                ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'active'); })
                ->first();

            if (!$inMov) {
                $this->error("    ✗ Thiếu movement IN tại kho đích");
                $allOk = false;
            } elseif ($inMov->unit_cost <= 0 || $inMov->amount <= 0) {
                $this->warn("    ⚠ Movement IN thiếu unit_cost/amount (transfer cũ trước khi có AVCO fix)");
                $allOk = false;
            } else {
                $this->line("    ✓ IN movement: qty={$inMov->quantity}, unit_cost={$inMov->unit_cost}, amount={$inMov->amount}");
            }

            // 3. Kiểm tra inventory_balance kho đích
            $destBalance = InventoryBalance::where('product_id', $item->product_id)
                ->where('warehouse_id', $transfer->to_warehouse_id)
                ->first();

            if (!$destBalance) {
                $this->warn("    ⚠ inventory_balances kho đích chưa được tạo (transfer cũ cần reconcile)");
                $allOk = false;
            } else {
                $this->line("    ✓ Kho đích AVCO: qty={$destBalance->qty_on_hand}, avg_cost={$destBalance->avg_cost}, value={$destBalance->value_on_hand}");
            }

            // 4. Kiểm tra tổng giá trị không đổi (sum amount phải = 0 khi cộng out+in)
            if ($outMov && $inMov) {
                $netAmount = (float)$outMov->amount + (float)$inMov->amount;
                if (abs($netAmount) > 1) {
                    $this->error("    ✗ Tổng giá trị out+in không = 0 (lệch {$netAmount}) — giá trị tồn toàn hệ thống bị sai");
                    $allOk = false;
                } else {
                    $this->line("    ✓ Net amount = 0 (giá trị tồn toàn hệ thống không đổi)");
                }
            }

            // 5. Kiểm tra không có JE/WIP được tạo
            $jeCount = DB::table('journal_entries')
                ->where('reference_type', 'App\\Models\\StockTransfer')
                ->where('reference_id', $transfer->id)
                ->count();
            if ($jeCount > 0) {
                $this->error("    ✗ Có {$jeCount} JE gắn với phiếu chuyển kho — không được có WIP/COGS cho chuyển kho");
                $allOk = false;
            } else {
                $this->line("    ✓ Không có JE/WIP (đúng — chuyển kho không hạch toán COGS)");
            }
        }

        $this->line($allOk ? "  → OK\n" : "  → CÓ VẤN ĐỀ\n");
        return $allOk;
    }
}
