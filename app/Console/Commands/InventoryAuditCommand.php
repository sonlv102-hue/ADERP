<?php

namespace App\Console\Commands;

use App\Enums\StockEntryStatus;
use App\Enums\StockExitStatus;
use App\Models\StockEntry;
use App\Models\StockExit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryAuditCommand extends Command
{
    protected $signature = 'inventory:audit
                            {--scope=all : Phạm vi: all|entries|exits|lots|po|gl}
                            {--warehouse= : Lọc theo warehouse_id}';

    protected $description = 'Rà soát tính nhất quán tồn kho: movement, lot, PO received_qty, và GL.';

    private array $findings = [];

    public function handle(): int
    {
        $scope = $this->option('scope');

        $this->info('=== Kiểm tra tồn kho ===');

        if (in_array($scope, ['all', 'entries'])) $this->auditEntries();
        if (in_array($scope, ['all', 'exits']))   $this->auditExits();
        if (in_array($scope, ['all', 'lots']))    $this->auditLots();
        if (in_array($scope, ['all', 'po']))      $this->auditPo();
        if (in_array($scope, ['all', 'gl']))      $this->auditGl();

        $this->printFindings();

        return empty($this->findings) ? self::SUCCESS : self::FAILURE;
    }

    // ─── Entry checks ──────────────────────────────────────────────────────────

    private function auditEntries(): void
    {
        $this->line('');
        $this->line('[E] Kiểm tra phiếu nhập kho...');

        // E1: Confirmed entries nhưng không có movement nhập (+quantity)
        $noMovement = DB::table('stock_entries as e')
            ->leftJoin('stock_movements as m', function ($j) {
                $j->on('m.source_id', '=', 'e.id')
                  ->where('m.source_type', '=', \App\Models\StockEntry::class)
                  ->where('m.quantity', '>', 0);
            })
            ->where('e.status', StockEntryStatus::Confirmed->value)
            ->whereNull('m.id')
            ->select('e.id', 'e.code')
            ->get();

        foreach ($noMovement as $row) {
            $this->addFinding('E1', 'critical', "StockEntry #{$row->id} ({$row->code}) Confirmed nhưng không có movement nhập dương.");
        }

        // E2: Cancelled entries vẫn còn net movement != 0 (movement chưa được đảo)
        $cancelledWithNet = DB::table('stock_entries as e')
            ->join('stock_movements as m', function ($j) {
                $j->on('m.source_id', '=', 'e.id')
                  ->where('m.source_type', '=', \App\Models\StockEntry::class);
            })
            ->where('e.status', StockEntryStatus::Cancelled->value)
            ->groupBy('e.id', 'e.code', 'm.product_id', 'm.warehouse_id')
            ->havingRaw('ABS(SUM(m.quantity)) > 0')
            ->select('e.id', 'e.code', 'm.product_id', 'm.warehouse_id', DB::raw('SUM(m.quantity) as net_qty'))
            ->get();

        foreach ($cancelledWithNet as $row) {
            $this->addFinding('E2', 'warning', "StockEntry #{$row->id} ({$row->code}) Cancelled nhưng net movement = {$row->net_qty} (product #{$row->product_id}, warehouse #{$row->warehouse_id}). Thiếu bút toán đảo kho.");
        }

        // E3: Confirmed entries không có journal entry
        $noJe = DB::table('stock_entries as e')
            ->leftJoin('journal_entries as je', function ($j) {
                $j->on('je.reference_id', '=', 'e.id')
                  ->where('je.reference_type', '=', 'stock_entry')
                  ->whereNotIn('je.status', ['reversed', 'voided'])
                  ->whereRaw("je.description NOT LIKE 'Đảo:%'");
            })
            ->where('e.status', StockEntryStatus::Confirmed->value)
            ->whereNull('je.id')
            ->select('e.id', 'e.code')
            ->get();

        foreach ($noJe as $row) {
            $this->addFinding('E3', 'warning', "StockEntry #{$row->id} ({$row->code}) Confirmed nhưng không có journal entry hạch toán.");
        }

        $this->line("  E1: {$noMovement->count()} vi phạm  E2: {$cancelledWithNet->count()} vi phạm  E3: {$noJe->count()} vi phạm");
    }

    // ─── Exit checks ───────────────────────────────────────────────────────────

    private function auditExits(): void
    {
        $this->line('');
        $this->line('[X] Kiểm tra phiếu xuất kho...');

        // X1: Confirmed exits không có movement xuất (-quantity)
        $noMovement = DB::table('stock_exits as x')
            ->leftJoin('stock_movements as m', function ($j) {
                $j->on('m.source_id', '=', 'x.id')
                  ->where('m.source_type', '=', \App\Models\StockExit::class)
                  ->where('m.quantity', '<', 0);
            })
            ->where('x.status', StockExitStatus::Confirmed->value)
            ->whereNull('m.id')
            ->select('x.id', 'x.code')
            ->get();

        foreach ($noMovement as $row) {
            $this->addFinding('X1', 'critical', "StockExit #{$row->id} ({$row->code}) Confirmed nhưng không có movement xuất âm.");
        }

        // X2: Cancelled exits vẫn còn net movement != 0
        $cancelledWithNet = DB::table('stock_exits as x')
            ->join('stock_movements as m', function ($j) {
                $j->on('m.source_id', '=', 'x.id')
                  ->where('m.source_type', '=', \App\Models\StockExit::class);
            })
            ->where('x.status', StockExitStatus::Cancelled->value)
            ->groupBy('x.id', 'x.code', 'm.product_id', 'm.warehouse_id')
            ->havingRaw('ABS(SUM(m.quantity)) > 0')
            ->select('x.id', 'x.code', 'm.product_id', 'm.warehouse_id', DB::raw('SUM(m.quantity) as net_qty'))
            ->get();

        foreach ($cancelledWithNet as $row) {
            $this->addFinding('X2', 'warning', "StockExit #{$row->id} ({$row->code}) Cancelled nhưng net movement = {$row->net_qty} (product #{$row->product_id}, warehouse #{$row->warehouse_id}).");
        }

        // X3: Confirmed exits có project_id nhưng không có WIP entry
        $noWip = DB::table('stock_exits as x')
            ->leftJoin('project_wip_entries as w', function ($j) {
                $j->on('w.source_id', '=', 'x.id')
                  ->where('w.source_type', '=', \App\Models\StockExit::class);
            })
            ->where('x.status', StockExitStatus::Confirmed->value)
            ->whereNotNull('x.project_id')
            ->whereNull('w.id')
            ->select('x.id', 'x.code', 'x.project_id')
            ->get();

        foreach ($noWip as $row) {
            $this->addFinding('X3', 'warning', "StockExit #{$row->id} ({$row->code}) xuất dự án #{$row->project_id} nhưng không có WIP entry (TK 154).");
        }

        $this->line("  X1: {$noMovement->count()} vi phạm  X2: {$cancelledWithNet->count()} vi phạm  X3: {$noWip->count()} vi phạm");
    }

    // ─── Lot checks ────────────────────────────────────────────────────────────

    private function auditLots(): void
    {
        $this->line('');
        $this->line('[L] Kiểm tra project inventory lots...');

        // L1: issued_qty > received_qty (over-allocated)
        $overAllocated = DB::table('project_inventory_lots')
            ->whereRaw('issued_qty > received_qty')
            ->select('id', 'project_id', 'product_id', 'received_qty', 'issued_qty')
            ->get();

        foreach ($overAllocated as $row) {
            $this->addFinding('L1', 'critical', "Lot #{$row->id} (project #{$row->project_id}, product #{$row->product_id}): issued_qty={$row->issued_qty} > received_qty={$row->received_qty}. Over-allocated!");
        }

        // L2: Lot active nhưng entry gốc bị cancelled
        $orphanLots = DB::table('project_inventory_lots as l')
            ->join('stock_entries as e', 'e.id', '=', 'l.stock_entry_id')
            ->where('l.status', 'active')
            ->where('e.status', StockEntryStatus::Cancelled->value)
            ->select('l.id', 'l.project_id', 'l.product_id', 'l.stock_entry_id')
            ->get();

        foreach ($orphanLots as $row) {
            $this->addFinding('L2', 'critical', "Lot #{$row->id} vẫn active nhưng StockEntry #{$row->stock_entry_id} đã cancelled. Lot cần được đánh dấu cancelled.");
        }

        $this->line("  L1: {$overAllocated->count()} vi phạm  L2: {$orphanLots->count()} vi phạm");
    }

    // ─── PO received_quantity checks ───────────────────────────────────────────

    private function auditPo(): void
    {
        $this->line('');
        $this->line('[P] Kiểm tra received_quantity đơn mua...');

        // P1: PO item received_quantity > quantity (over-received)
        $overReceived = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereRaw('poi.received_quantity > poi.quantity')
            ->select('poi.id', 'poi.purchase_order_id', 'po.code as po_code', 'poi.product_id', 'poi.quantity', 'poi.received_quantity')
            ->get();

        foreach ($overReceived as $row) {
            $this->addFinding('P1', 'critical', "PO item #{$row->id} ({$row->po_code}, product #{$row->product_id}): received_qty={$row->received_quantity} > ordered_qty={$row->quantity}.");
        }

        // P2: PO item received_quantity không khớp với confirmed entry quantities
        $mismatch = DB::table('purchase_order_items as poi')
            ->join('stock_entry_items as sei', 'sei.purchase_order_item_id', '=', 'poi.id')
            ->join('stock_entries as se', 'se.id', '=', 'sei.stock_entry_id')
            ->where('se.status', StockEntryStatus::Confirmed->value)
            ->groupBy('poi.id', 'poi.received_quantity')
            ->havingRaw('ABS(poi.received_quantity - SUM(sei.quantity)) > 0.01')
            ->select('poi.id', 'poi.purchase_order_id', 'poi.received_quantity', DB::raw('SUM(sei.quantity) as confirmed_sum'))
            ->get();

        foreach ($mismatch as $row) {
            $this->addFinding('P2', 'warning', "PO item #{$row->id}: received_quantity={$row->received_quantity} nhưng sum confirmed entries={$row->confirmed_sum}. Không khớp.");
        }

        $this->line("  P1: {$overReceived->count()} vi phạm  P2: {$mismatch->count()} vi phạm");
    }

    // ─── GL reconciliation ─────────────────────────────────────────────────────

    private function auditGl(): void
    {
        $this->line('');
        $this->line('[G] Đối soát GL vs tồn kho...');

        // G1: Tổng amount movement nhập (excl project) vs số dư GL 156x
        $movementValue = DB::table('stock_movements as m')
            ->join('stock_entries as e', function ($j) {
                $j->on('e.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', \App\Models\StockEntry::class);
            })
            ->whereIn('e.status', [StockEntryStatus::Confirmed->value])
            ->whereNotNull('m.amount')
            ->selectRaw('SUM(m.amount) as total_value')
            ->value('total_value') ?? 0;

        $glValue = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_code', 'LIKE', '156%')
            ->selectRaw('SUM(jl.debit) - SUM(jl.credit) as balance')
            ->value('balance') ?? 0;

        $diff = abs((float)$movementValue - (float)$glValue);
        if ($diff > 1000) {
            $this->addFinding('G1', 'warning', sprintf(
                "GL TK 156x (%.0f) lệch với tổng giá trị movement nhập (%.0f). Chênh: %.0f",
                $glValue, $movementValue, $glValue - $movementValue
            ));
        } else {
            $this->line("  G1: GL 156x khớp với movement (chênh < 1.000đ). OK.");
        }

        // G2: GL 154 vs tổng WIP entries
        $wipTotal = DB::table('project_wip_entries')->sum('amount') ?? 0;
        $gl154    = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_code', 'LIKE', '154%')
            ->selectRaw('SUM(jl.debit) - SUM(jl.credit) as balance')
            ->value('balance') ?? 0;

        $diff154 = abs((float)$wipTotal - (float)$gl154);
        if ($diff154 > 1000) {
            $this->addFinding('G2', 'warning', sprintf(
                "GL TK 154 (%.0f) lệch với tổng WIP entries (%.0f). Chênh: %.0f",
                $gl154, $wipTotal, $gl154 - $wipTotal
            ));
        } else {
            $this->line("  G2: GL 154 khớp với WIP entries (chênh < 1.000đ). OK.");
        }
    }

    // ─── Output ────────────────────────────────────────────────────────────────

    private function addFinding(string $code, string $severity, string $message): void
    {
        $this->findings[] = ['code' => $code, 'severity' => $severity, 'message' => $message];
    }

    private function printFindings(): void
    {
        $this->line('');
        $critical = array_filter($this->findings, fn($f) => $f['severity'] === 'critical');
        $warnings = array_filter($this->findings, fn($f) => $f['severity'] === 'warning');

        if (empty($this->findings)) {
            $this->info('✓ Không tìm thấy vấn đề nào.');
            return;
        }

        $this->error('=== Kết quả (' . count($critical) . ' critical, ' . count($warnings) . ' warning) ===');

        foreach ($this->findings as $f) {
            $label = $f['severity'] === 'critical' ? '<fg=red>[CRITICAL]</>' : '<fg=yellow>[WARNING]</>';
            $this->line("  {$label} [{$f['code']}] {$f['message']}");
        }
    }
}
