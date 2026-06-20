<?php

namespace App\Console\Commands;

use App\Models\StockEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic command. Tuyệt đối không INSERT/UPDATE/DELETE.
 * Mục tiêu: tách bạch 2 nguyên nhân lệch GL 156x vs stock movements:
 *   (1) Movements có amount = NULL → bị bỏ qua bởi inventory:audit G1
 *   (2) Old JEs có giá trị Dr 156x sai do bug VAT trước 2026-06-09
 */
class DiagnoseInventory156x extends Command
{
    protected $signature = 'inventory:diagnose-156x';
    protected $description = '[READ-ONLY] Chẩn đoán nguyên nhân lệch GL 156x vs stock_movements (~253M VND)';

    private const VAT_FIX_DATE = '2026-06-09';
    private const KNOWN_DIFF    = 253_876_112;

    public function handle(): int
    {
        $this->info('=== INVENTORY 156x DIAGNOSTIC — READ ONLY ===');
        $this->newLine();

        $this->sectionA();
        $this->sectionB();
        $this->sectionC();
        $this->sectionD();

        return self::SUCCESS;
    }

    // ─── A. Xác nhận con số lệch và nguyên nhân NULL amount ───────────────────

    private function sectionA(): void
    {
        $this->info('── A. XÁC NHẬN CON SỐ LỆCH ──────────────────────────────────────────────');

        // GL 156x: SUM(debit) - SUM(credit) trên posted JEs — đúng công thức G1
        $glBalance = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('jl.account_code', 'LIKE', '156%')
            ->selectRaw('SUM(jl.debit) - SUM(jl.credit) as balance')
            ->value('balance');

        // Movement value (amount NOT NULL) — công thức G1
        $movWithAmount = (float) DB::table('stock_movements as m')
            ->join('stock_entries as e', function ($j) {
                $j->on('e.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', StockEntry::class);
            })
            ->whereIn('e.status', ['confirmed'])
            ->whereNotNull('m.amount')
            ->selectRaw('SUM(m.amount) as total')
            ->value('total');

        $diffG1 = $glBalance - $movWithAmount;

        // Movements có amount = NULL
        $nullCount = (int) DB::table('stock_movements as m')
            ->join('stock_entries as e', function ($j) {
                $j->on('e.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', StockEntry::class);
            })
            ->whereIn('e.status', ['confirmed'])
            ->whereNull('m.amount')
            ->count();

        $nullQty = (float) DB::table('stock_movements as m')
            ->join('stock_entries as e', function ($j) {
                $j->on('e.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', StockEntry::class);
            })
            ->whereIn('e.status', ['confirmed'])
            ->whereNull('m.amount')
            ->sum('m.quantity');

        // Với NULL-amount movements: ước tính giá trị từ JE Dr 156x của stock entry tương ứng
        $nullMovStockEntryIds = DB::table('stock_movements as m')
            ->join('stock_entries as e', function ($j) {
                $j->on('e.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', StockEntry::class);
            })
            ->whereIn('e.status', ['confirmed'])
            ->whereNull('m.amount')
            ->pluck('e.id')
            ->unique();

        $jeValueForNullMovEntries = 0.0;
        if ($nullMovStockEntryIds->isNotEmpty()) {
            $jeValueForNullMovEntries = (float) DB::table('journal_entry_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.status', 'posted')
                ->where('je.reference_type', 'stock_entry')
                ->whereIn('je.reference_id', $nullMovStockEntryIds)
                ->where('jl.account_code', 'LIKE', '156%')
                ->where('jl.debit', '>', 0)
                ->sum('jl.debit');
        }

        // Với NOT-NULL movements: GL Dr 156x tương ứng
        $notNullMovStockEntryIds = DB::table('stock_movements as m')
            ->join('stock_entries as e', function ($j) {
                $j->on('e.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', StockEntry::class);
            })
            ->whereIn('e.status', ['confirmed'])
            ->whereNotNull('m.amount')
            ->pluck('e.id')
            ->unique();

        $jeValueForNotNullMovEntries = 0.0;
        if ($notNullMovStockEntryIds->isNotEmpty()) {
            $jeValueForNotNullMovEntries = (float) DB::table('journal_entry_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.status', 'posted')
                ->where('je.reference_type', 'stock_entry')
                ->whereIn('je.reference_id', $notNullMovStockEntryIds)
                ->where('jl.account_code', 'LIKE', '156%')
                ->where('jl.debit', '>', 0)
                ->sum('jl.debit');
        }

        $diffNotNull = $jeValueForNotNullMovEntries - $movWithAmount;

        $this->table(['Nguồn', 'Giá trị (VND)'], [
            ['GL Dr 156x net (posted JEs)', number_format($glBalance)],
            ['Movement SUM(amount) — NOT NULL, source=StockEntry confirmed', number_format($movWithAmount)],
            ['─── Lệch G1 = GL − movements ───', number_format($diffG1)],
            ['─── Known diff từ inventory:audit ───', number_format(self::KNOWN_DIFF)],
        ]);

        $this->newLine();
        $this->line("  Movements có amount = NULL: {$nullCount} bản ghi, qty = " . number_format($nullQty, 3));
        $this->line("  Stock entries tương ứng:    {$nullMovStockEntryIds->count()} phiếu NK");
        $this->line("  JE Dr 156x cho NULL-amount entries: " . number_format($jeValueForNullMovEntries));
        $this->newLine();
        $this->line("  Phân tích: trong tổng GL = {$nullMovStockEntryIds->count()} entries NULL + {$notNullMovStockEntryIds->count()} entries NOT-NULL");
        $this->line("  JE Dr 156x (NOT-NULL entries): " . number_format($jeValueForNotNullMovEntries));
        $this->line("  Movement amount (NOT-NULL):     " . number_format($movWithAmount));
        $this->line("  Lệch trong nhóm NOT-NULL:      " . number_format($diffNotNull) . " (lý tưởng phải = 0)");
        $this->newLine();

        if (abs($jeValueForNullMovEntries - $diffG1) < 100_000) {
            $this->info("  → KẾT LUẬN A: Khoản lệch " . number_format($diffG1) . " ≈ JE Dr 156x của " . $nullMovStockEntryIds->count() . " entries có amount=NULL.");
            $this->info("    NULL amount trên stock_movements là nguyên nhân chính, KHÔNG phải tính sai giá trị.");
        } else {
            $this->warn("  → KẾT LUẬN A: Lệch phức tạp hơn — NULL amount chỉ giải thích " . number_format($jeValueForNullMovEntries));
            $this->warn("    Còn " . number_format($diffG1 - $jeValueForNullMovEntries) . " chưa giải thích được. Xem mục D.");
        }
        $this->newLine();
    }

    // ─── B. 33 Purchase Invoices thiếu JE ─────────────────────────────────────

    private function sectionB(): void
    {
        $this->info('── B. PURCHASE INVOICES invoice_type=NULL KHÔNG CÓ JE ────────────────────');

        $piRows = DB::table('purchase_invoices as pi')
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->whereNull('pi.invoice_type')
            ->whereNotIn('pi.status', ['cancelled'])
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->where('je.reference_type', 'purchase_invoice')
                    ->whereColumn('je.reference_id', 'pi.id')
                    ->whereIn('je.status', ['draft', 'posted']);
            })
            ->select([
                'pi.id', 'pi.code', 'pi.invoice_date', 'pi.status',
                'pi.subtotal', 'pi.tax_amount', 'pi.total',
                DB::raw("po.id as po_id"),
                DB::raw("po.code as po_code"),
                DB::raw("s.name as supplier_name"),
            ])
            ->orderBy('pi.id')
            ->get();

        // Kiểm tra: PI nào có confirmed stock_entry (→ GL 156x đã có JE từ NK)
        $poIds = $piRows->pluck('po_id')->filter()->unique();
        $posWithConfirmedEntry = $poIds->isNotEmpty()
            ? DB::table('stock_entries')
                ->whereIn('purchase_order_id', $poIds)
                ->where('status', 'confirmed')
                ->pluck('purchase_order_id')
                ->unique()
                ->all()
            : [];

        $totalSubtotal = $piRows->sum('subtotal');
        $totalTotal    = $piRows->sum('total');

        $this->line("  Tìm thấy {$piRows->count()} PI với invoice_type = NULL và không có JE:");
        $this->table(
            ['ID', 'Mã HĐ', 'Ngày HĐ', 'TT', 'Subtotal', 'Thuế', 'NCC', 'ĐM', 'Có NK confirmed?'],
            $piRows->map(fn ($r) => [
                $r->id,
                $r->code,
                $r->invoice_date ?? '—',
                $r->status,
                number_format($r->subtotal),
                number_format($r->tax_amount),
                mb_strimwidth($r->supplier_name ?? '', 0, 22, '…'),
                $r->po_code ?? '—',
                in_array($r->po_id, $posWithConfirmedEntry) ? 'CÓ (JE đã có)' : 'KHÔNG',
            ])->all()
        );

        $withNk  = $piRows->filter(fn ($r) => in_array($r->po_id, $posWithConfirmedEntry))->count();
        $withoutNk = $piRows->count() - $withNk;

        $this->line("  TỔNG subtotal: " . number_format($totalSubtotal) . " VND");
        $this->line("  TỔNG total (incl VAT): " . number_format($totalTotal) . " VND");
        $this->newLine();
        $this->line("  PI có confirmed stock_entry (GL 156x đã có): {$withNk}");
        $this->line("  PI KHÔNG có stock_entry nào:                 {$withoutNk}");
        $this->newLine();
        $this->warn("  → 33 PI thiếu JE = thiếu JE hạch toán AP (Cr 3311), KHÔNG phải thiếu Dr 156x.");
        $this->warn("    GL 156x đã đúng/đủ nếu NK tương ứng đã confirmed. Ảnh hưởng: BẢNG CÂN ĐỐI sai TK 3311.");
        $this->newLine();
    }

    // ─── C. So sánh các nguồn lệch ────────────────────────────────────────────

    private function sectionC(): void
    {
        $this->info('── C. TỔNG HỢP NGUỒN LỆCH ────────────────────────────────────────────────');

        // Lấy stock entries không có amount trên bất kỳ movement nào
        $noAmountEntries = DB::table('stock_movements as m')
            ->join('stock_entries as se', function ($j) {
                $j->on('se.id', '=', 'm.source_id')
                  ->where('m.source_type', '=', StockEntry::class);
            })
            ->where('se.status', 'confirmed')
            ->groupBy('se.id', 'se.code', 'se.entry_date', 'se.created_at')
            ->havingRaw('COUNT(*) > 0 AND SUM(CASE WHEN m.amount IS NOT NULL THEN 1 ELSE 0 END) = 0')
            ->select('se.id', 'se.code', 'se.entry_date', 'se.created_at',
                DB::raw('SUM(m.quantity) as total_qty'),
                DB::raw('COUNT(*) as movement_count'))
            ->orderBy('se.created_at')
            ->get();

        // JE Dr 156x cho từng entry thiếu amount
        $entryIds = $noAmountEntries->pluck('id');
        $je156xPerEntry = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.reference_type', 'stock_entry')
            ->whereIn('je.reference_id', $entryIds)
            ->where('jl.account_code', 'LIKE', '156%')
            ->where('jl.debit', '>', 0)
            ->groupBy('je.reference_id')
            ->select('je.reference_id as se_id', DB::raw('SUM(jl.debit) as je_156x'))
            ->get()
            ->keyBy('se_id');

        // unit_price * qty từ entry items
        $itemValuePerEntry = DB::table('stock_entry_items')
            ->whereIn('stock_entry_id', $entryIds)
            ->groupBy('stock_entry_id')
            ->select('stock_entry_id', DB::raw('SUM(unit_price * quantity) as item_value'))
            ->get()
            ->keyBy('stock_entry_id');

        $this->line("  Stock entries có movement.amount = NULL toàn bộ: {$noAmountEntries->count()} phiếu");
        $this->newLine();

        if ($noAmountEntries->isNotEmpty()) {
            $this->table(
                ['ID', 'Mã NK', 'Ngày NK', 'Qty', 'JE Dr 156x', 'Items unit_price×qty', 'Lệch JE vs items'],
                $noAmountEntries->map(function ($se) use ($je156xPerEntry, $itemValuePerEntry) {
                    $je    = (float) ($je156xPerEntry[$se->id]->je_156x ?? 0);
                    $items = (float) ($itemValuePerEntry[$se->id]->item_value ?? 0);
                    return [
                        $se->id,
                        $se->code,
                        substr($se->entry_date ?? $se->created_at, 0, 10),
                        number_format($se->total_qty, 3),
                        number_format($je),
                        number_format($items),
                        number_format($je - $items),
                    ];
                })->all()
            );

            $totalJe = $je156xPerEntry->sum('je_156x');
            $this->line("  Tổng JE Dr 156x cho entries NULL-amount: " . number_format($totalJe));
            $this->line("  Lệch đã biết (G1):                       " . number_format(self::KNOWN_DIFF));
            $this->line("  Giải thích được bởi NULL-amount:         " . number_format($totalJe));
            $this->line("  Còn lại chưa giải thích:                 " . number_format(self::KNOWN_DIFF - $totalJe));
        }
        $this->newLine();
    }

    // ─── D. Old stock entries (trước VAT fix 2026-06-09) — kiểm tra VAT JE ───

    private function sectionD(): void
    {
        $cutoff = self::VAT_FIX_DATE;
        $this->info("── D. STOCK ENTRIES TRƯỚC {$cutoff} — SO SÁNH JE Dr 156x vs unit_price×qty ──");

        // Batch query: tất cả confirmed entries trước cutoff có posted JE
        $entries = DB::table('stock_entries as se')
            ->where('se.status', 'confirmed')
            ->where('se.created_at', '<', $cutoff)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->where('je.reference_type', 'stock_entry')
                    ->whereColumn('je.reference_id', 'se.id')
                    ->where('je.status', 'posted');
            })
            ->select('se.id', 'se.code', 'se.entry_date', 'se.created_at')
            ->orderBy('se.created_at')
            ->get();

        $this->line("  Stock entries confirmed trước {$cutoff} có posted JE: {$entries->count()} phiếu");

        if ($entries->isEmpty()) {
            $this->line("  ✓ Không có entry nào. Bỏ qua.");
            $this->newLine();
            return;
        }

        $entryIds = $entries->pluck('id');

        // Batch: JE Dr 156x per entry
        $je156xMap = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.reference_type', 'stock_entry')
            ->whereIn('je.reference_id', $entryIds)
            ->where('jl.account_code', 'LIKE', '156%')
            ->where('jl.debit', '>', 0)
            ->groupBy('je.reference_id')
            ->select('je.reference_id as se_id', DB::raw('SUM(jl.debit) as je_156x'))
            ->get()
            ->keyBy('se_id');

        // Batch: unit_price × qty per entry
        $itemValueMap = DB::table('stock_entry_items')
            ->whereIn('stock_entry_id', $entryIds)
            ->groupBy('stock_entry_id')
            ->select('stock_entry_id', DB::raw('SUM(unit_price * quantity) as item_value'))
            ->get()
            ->keyBy('stock_entry_id');

        // Batch: movement.amount per entry (SUM of positive movements)
        $movAmountMap = DB::table('stock_movements as m')
            ->whereIn('m.source_id', $entryIds)
            ->where('m.source_type', StockEntry::class)
            ->where('m.quantity', '>', 0)
            ->groupBy('m.source_id')
            ->select('m.source_id as se_id', DB::raw('SUM(m.amount) as mov_amount'))
            ->get()
            ->keyBy('se_id');

        $issues   = [];
        $matching = 0;
        $totalJeOld = 0.0;
        $totalItemsOld = 0.0;
        $totalMovOld = 0.0;

        foreach ($entries as $se) {
            $je    = (float) ($je156xMap[$se->id]->je_156x ?? 0);
            $items = (float) ($itemValueMap[$se->id]->item_value ?? 0);
            $mov   = $movAmountMap[$se->id]->mov_amount ?? null; // null = no amount backfilled

            $totalJeOld    += $je;
            $totalItemsOld += $items;
            $totalMovOld   += (float) $mov;

            $diffJeItems = $je - $items;
            if (abs($diffJeItems) > 1_000) {
                $issues[] = [
                    $se->code,
                    substr($se->entry_date ?? $se->created_at, 0, 10),
                    number_format($je),
                    number_format($items),
                    number_format($diffJeItems),
                    is_null($mov) ? 'NULL' : number_format($mov),
                    $diffJeItems > 0 ? 'JE CÓ VAT' : 'JE THẤP HƠN',
                ];
            } else {
                $matching++;
            }
        }

        $this->newLine();
        $this->line("  Kết quả: {$matching}/{$entries->count()} entries có JE Dr 156x ≈ unit_price×qty (dung sai 1,000 VND)");
        $this->line("  Entries có sai lệch: " . count($issues));
        $this->newLine();

        if (!empty($issues)) {
            $this->warn("  Chi tiết entries có JE Dr 156x ≠ unit_price×qty:");
            $this->table(
                ['Phiếu NK', 'Ngày NK', 'JE Dr 156x', 'Items unit_price×qty', 'Lệch JE-Items', 'Mov amount', 'Nhận xét'],
                $issues
            );
        }

        $this->newLine();
        $this->line("  TỔNG entries trước {$cutoff}:");
        $this->table(['Chỉ tiêu', 'Giá trị (VND)'], [
            ['Tổng JE Dr 156x',              number_format($totalJeOld)],
            ['Tổng unit_price×qty (items)',  number_format($totalItemsOld)],
            ['Tổng movement.amount',         number_format($totalMovOld)],
            ['Lệch JE vs items',             number_format($totalJeOld - $totalItemsOld)],
            ['Lệch JE vs mov.amount',        number_format($totalJeOld - $totalMovOld)],
        ]);

        // So sánh baseline: entries SAU cutoff
        $newEntries = DB::table('stock_entries as se')
            ->where('se.status', 'confirmed')
            ->where('se.created_at', '>=', $cutoff)
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->where('je.reference_type', 'stock_entry')
                    ->whereColumn('je.reference_id', 'se.id')
                    ->where('je.status', 'posted');
            })
            ->select('se.id')
            ->get();

        if ($newEntries->isNotEmpty()) {
            $newIds = $newEntries->pluck('id');
            $newJe = (float) DB::table('journal_entry_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->where('je.status', 'posted')->where('je.reference_type', 'stock_entry')
                ->whereIn('je.reference_id', $newIds)
                ->where('jl.account_code', 'LIKE', '156%')->where('jl.debit', '>', 0)
                ->sum('jl.debit');
            $newItems = (float) DB::table('stock_entry_items')
                ->whereIn('stock_entry_id', $newIds)
                ->sum(DB::raw('unit_price * quantity'));

            $this->newLine();
            $this->line("  Baseline — entries TỪ {$cutoff} ({$newEntries->count()} phiếu):");
            $this->line("    Tổng JE Dr 156x:   " . number_format($newJe));
            $this->line("    Tổng items value:  " . number_format($newItems));
            $this->line("    Lệch (lý tưởng=0): " . number_format($newJe - $newItems));
        }
        $this->newLine();
    }
}
