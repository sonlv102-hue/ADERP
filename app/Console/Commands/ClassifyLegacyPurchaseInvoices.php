<?php

namespace App\Console\Commands;

use App\Enums\PurchaseInvoiceType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phân loại invoice_type cho các purchase_invoices cũ (invoice_type = NULL).
 *
 * Điều kiện nhận dạng: invoice_type IS NULL, có confirmed stock_entry.
 * Logic: dùng dominant line_type từ PO items → map sang PurchaseInvoiceType.
 *
 * Chốt an toàn:
 *   - Snapshot GL TK 3311 + 156x trước/sau khi ghi — phải bằng nhau tuyệt đối.
 *   - PI có PO items mâu thuẫn (mixed project / mixed line_type) → CONFLICT, không tự gán.
 *   - Toàn bộ gói trong một DB::transaction; nếu GL thay đổi → rollback + error.
 *   - Activity log mỗi PI được sửa.
 */
class ClassifyLegacyPurchaseInvoices extends Command
{
    protected $signature = 'purchase-invoices:classify-legacy
                            {--dry-run : Hiển thị phân loại đề xuất, không ghi dữ liệu}
                            {--apply   : Chạy thật, ghi invoice_type vào DB}';

    protected $description = 'Phân loại invoice_type cho purchase_invoices cũ (invoice_type = NULL có confirmed stock_entry)';

    // Mapping line_type → PurchaseInvoiceType (chỉ các loại inventory-backed là an toàn cho goods PI)
    private const LINE_TYPE_MAP = [
        'goods'       => PurchaseInvoiceType::ResaleGoods,
        'material'    => PurchaseInvoiceType::RawMaterial,
        'tool'        => PurchaseInvoiceType::ToolsEquipment,
        'fixed_asset' => PurchaseInvoiceType::FixedAsset,
    ];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isApply  = $this->option('apply');

        if (!$isDryRun && !$isApply) {
            $this->error('Vui lòng chỉ định --dry-run hoặc --apply.');
            return self::FAILURE;
        }

        $this->info('=== PHÂN LOẠI LEGACY PURCHASE INVOICES ===');
        $this->line('');

        // Snapshot GL trước
        $glBefore = $this->snapshotGl();
        $this->line("GL trước: TK 156x = " . number_format($glBefore['gl_156x']) .
                    " | TK 3311 = " . number_format($glBefore['gl_3311']));
        $this->line('');

        // Tìm tất cả PI legacy
        $pis = $this->findLegacyPis();
        $this->line("Tìm thấy {$pis->count()} purchase invoices có invoice_type = NULL và confirmed stock_entry.");
        $this->line('');

        // Phân tích PO items và phân loại
        [$autoList, $conflictList] = $this->classifyAll($pis);

        // In danh sách tự phân loại được
        $this->printAutoList($autoList);

        // In danh sách mâu thuẫn
        $this->printConflictList($conflictList);

        if ($isDryRun) {
            $this->line('');
            $this->comment('─── Chế độ --dry-run: không ghi dữ liệu ───');
            $this->info("Có thể tự phân loại: {$autoList->count()} PI");
            $this->warn("Mâu thuẫn (cần duyệt thủ công): {$conflictList->count()} PI");
            return self::SUCCESS;
        }

        // ─── APPLY ──────────────────────────────────────────────────────────
        if ($autoList->isEmpty()) {
            $this->warn('Không có PI nào để phân loại tự động.');
            return self::SUCCESS;
        }

        $confirmed = $this->confirm(
            "Ghi invoice_type cho {$autoList->count()} PI? (Có thể rollback nếu GL thay đổi)",
            false
        );
        if (!$confirmed) {
            $this->line('Hủy.');
            return self::SUCCESS;
        }

        $exitCode   = self::SUCCESS;
        $appliedCount = 0;

        try {
            DB::transaction(function () use ($autoList, $glBefore, &$appliedCount) {
                foreach ($autoList as $item) {
                    DB::table('purchase_invoices')
                        ->where('id', $item['pi_id'])
                        ->update(['invoice_type' => $item['proposed_type']->value]);

                    activity()
                        ->causedBy(null)
                        ->performedOn(\App\Models\PurchaseInvoice::find($item['pi_id']))
                        ->withProperties([
                            'invoice_type_set' => $item['proposed_type']->value,
                            'reason'           => 'classify-legacy: phân loại hóa đơn cũ invoice_type=NULL',
                            'line_types'       => $item['line_types'],
                            'project_ids'      => $item['project_ids'],
                        ])
                        ->log('classify_legacy');

                    $appliedCount++;
                }

                // ─── Chốt an toàn: GL không được thay đổi ──────────────────
                $glAfter = $this->snapshotGl();

                $diff156 = abs($glAfter['gl_156x'] - $glBefore['gl_156x']);
                $diff331 = abs($glAfter['gl_3311'] - $glBefore['gl_3311']);

                if ($diff156 > 0 || $diff331 > 0) {
                    throw new \RuntimeException(
                        "GL ĐÃ THAY ĐỔI NGOÀI DỰ KIẾN!\n" .
                        "  TK 156x: trước={$glBefore['gl_156x']} → sau={$glAfter['gl_156x']} (lệch={$diff156})\n" .
                        "  TK 3311: trước={$glBefore['gl_3311']} → sau={$glAfter['gl_3311']} (lệch={$diff331})\n" .
                        "Toàn bộ thay đổi đã được ROLLBACK."
                    );
                }

                $this->line('');
                $this->line("GL sau:  TK 156x = " . number_format($glAfter['gl_156x']) .
                            " | TK 3311 = " . number_format($glAfter['gl_3311']));
                $this->info('✓ GL không thay đổi — xác nhận command không đụng JE.');
            });

            $this->info("✓ Đã phân loại {$appliedCount} PI thành công.");

        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $exitCode = self::FAILURE;
        }

        if ($conflictList->isNotEmpty()) {
            $this->line('');
            $this->warn("{$conflictList->count()} PI mâu thuẫn chưa được xử lý. Xem chi tiết ở trên.");
        }

        return $exitCode;
    }

    // ─── Lấy danh sách PI cần xử lý ────────────────────────────────────────

    private function findLegacyPis()
    {
        return DB::table('purchase_invoices as pi')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->whereNull('pi.invoice_type')
            ->whereNotIn('pi.status', ['cancelled', 'pending'])
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('stock_entries as se')
                    ->whereColumn('se.purchase_order_id', 'pi.purchase_order_id')
                    ->where('se.status', 'confirmed');
            })
            ->orderBy('pi.id')
            ->select([
                'pi.id',
                'pi.code',
                'pi.invoice_date',
                'pi.purchase_order_id',
                'pi.supplier_id',
                'pi.subtotal',
                'pi.tax_amount',
                'pi.total',
                'pi.status',
                DB::raw('s.name as supplier_name'),
            ])
            ->get();
    }

    // ─── Phân tích và phân loại ─────────────────────────────────────────────

    private function classifyAll($pis): array
    {
        $autoList     = collect();
        $conflictList = collect();

        foreach ($pis as $pi) {
            $analysis = $this->analyzePiItems($pi);

            if ($analysis['conflict_reason']) {
                $conflictList->push(array_merge(['pi' => $pi], $analysis));
            } else {
                $autoList->push(array_merge(['pi_id' => $pi->id, 'pi' => $pi], $analysis));
            }
        }

        return [$autoList, $conflictList];
    }

    private function analyzePiItems(object $pi): array
    {
        $poItems = DB::table('purchase_order_items')
            ->where('purchase_order_id', $pi->purchase_order_id)
            ->select('line_type', 'project_id', 'quantity', 'unit_price')
            ->get();

        if ($poItems->isEmpty()) {
            return [
                'conflict_reason' => 'Không tìm thấy PO items — không thể suy luận',
                'proposed_type'   => null,
                'line_types'      => [],
                'project_ids'     => [],
                'has_project'     => false,
            ];
        }

        $distinctLineTypes = $poItems->pluck('line_type')->filter()->unique()->values()->toArray();
        $distinctProjects  = $poItems->pluck('project_id')->filter()->unique()->values()->toArray();
        $hasAnyProject     = $poItems->whereNotNull('project_id')->isNotEmpty();
        $hasAnyNoProject   = $poItems->whereNull('project_id')->isNotEmpty();

        // Mâu thuẫn project: vừa có dòng có project_id vừa có dòng không có
        if ($hasAnyProject && $hasAnyNoProject) {
            return [
                'conflict_reason' => 'Mixed project: một số dòng có project_id, một số không',
                'proposed_type'   => null,
                'line_types'      => $distinctLineTypes,
                'project_ids'     => $distinctProjects,
                'has_project'     => true,
            ];
        }

        // Mâu thuẫn project: nhiều project khác nhau
        if (count($distinctProjects) > 1) {
            return [
                'conflict_reason' => 'Multi-project: PO items thuộc ' . count($distinctProjects) . ' dự án khác nhau',
                'proposed_type'   => null,
                'line_types'      => $distinctLineTypes,
                'project_ids'     => $distinctProjects,
                'has_project'     => true,
            ];
        }

        // Mâu thuẫn line_type: nhiều loại khác nhau
        // Cho phép mixed goods+material+tool (đều là inventory-backed) nếu cùng "gia đình"
        $inventoryTypes  = ['goods', 'material', 'tool', 'fixed_asset'];
        $nonInventory    = array_diff($distinctLineTypes, $inventoryTypes);

        if (!empty($nonInventory)) {
            return [
                'conflict_reason' => 'Mixed line_type: có loại không phải hàng hóa (' . implode(', ', $nonInventory) . ')',
                'proposed_type'   => null,
                'line_types'      => $distinctLineTypes,
                'project_ids'     => $distinctProjects,
                'has_project'     => $hasAnyProject,
            ];
        }

        // Xác định dominant line_type (theo giá trị lớn nhất)
        $dominantLineType = $this->dominantLineType($poItems);
        $proposedType     = self::LINE_TYPE_MAP[$dominantLineType] ?? null;

        if (!$proposedType) {
            return [
                'conflict_reason' => "Không tìm được invoice_type cho line_type '{$dominantLineType}'",
                'proposed_type'   => null,
                'line_types'      => $distinctLineTypes,
                'project_ids'     => $distinctProjects,
                'has_project'     => $hasAnyProject,
            ];
        }

        return [
            'conflict_reason' => null,
            'proposed_type'   => $proposedType,
            'line_types'      => $distinctLineTypes,
            'project_ids'     => $distinctProjects,
            'has_project'     => $hasAnyProject,
        ];
    }

    private function dominantLineType($poItems): string
    {
        // Ưu tiên line_type có tổng giá trị lớn nhất
        $byValue = [];
        foreach ($poItems as $item) {
            $lt = $item->line_type ?? 'goods';
            $byValue[$lt] = ($byValue[$lt] ?? 0) + ((float) $item->unit_price * (float) $item->quantity);
        }
        arsort($byValue);
        return array_key_first($byValue);
    }

    // ─── In kết quả ─────────────────────────────────────────────────────────

    private function printAutoList($autoList): void
    {
        if ($autoList->isEmpty()) {
            $this->line('Không có PI nào được tự động phân loại.');
            return;
        }

        // Tách PI có project (cần xem kỹ hơn) vs không có project (thương mại thuần)
        $withProject    = $autoList->filter(fn ($i) => $i['has_project']);
        $withoutProject = $autoList->filter(fn ($i) => !$i['has_project']);

        if ($withoutProject->isNotEmpty()) {
            $this->info("─── TỰ ĐỘNG PHÂN LOẠI — THƯƠNG MẠI ({$withoutProject->count()} PI) ───");
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày HĐ', 'Tổng', 'Trạng thái', 'NCC', 'Line Types', 'Đề xuất'],
                $withoutProject->map(fn ($i) => [
                    $i['pi_id'],
                    $i['pi']->code,
                    $i['pi']->invoice_date,
                    number_format($i['pi']->total),
                    $i['pi']->status,
                    mb_strimwidth($i['pi']->supplier_name, 0, 20, '…'),
                    implode(', ', $i['line_types']),
                    $i['proposed_type']->value . ' (' . $i['proposed_type']->label() . ')',
                ])->all()
            );
        }

        if ($withProject->isNotEmpty()) {
            $this->line('');
            $this->warn("─── TỰ ĐỘNG PHÂN LOẠI — CÓ DỰ ÁN (SOI KỸ TRƯỚC KHI DUYỆT — {$withProject->count()} PI) ───");
            $this->line('  Lưu ý: đề xuất vẫn là inventory-backed type (an toàn). Nếu muốn dùng project_construction,');
            $this->line('  phải gán thủ công — vì loại đó KHÔNG phải inventory-backed, có thể trigger JE mới nếu tái xử lý.');
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày HĐ', 'Tổng', 'NCC', 'Line Types', 'Project IDs', 'Đề xuất'],
                $withProject->map(fn ($i) => [
                    $i['pi_id'],
                    $i['pi']->code,
                    $i['pi']->invoice_date,
                    number_format($i['pi']->total),
                    mb_strimwidth($i['pi']->supplier_name, 0, 18, '…'),
                    implode(', ', $i['line_types']),
                    implode(', ', array_map(fn ($id) => '#' . $id, $i['project_ids'])),
                    $i['proposed_type']->value . ' (' . $i['proposed_type']->label() . ')',
                ])->all()
            );
        }
    }

    private function printConflictList($conflictList): void
    {
        if ($conflictList->isEmpty()) {
            return;
        }

        $this->line('');
        $this->error("─── MÂU THUẪN — CẦN PHÂN LOẠI THỦ CÔNG ({$conflictList->count()} PI) ───");
        $this->line('  Command không tự gán. Sau khi bạn quyết định, cập nhật thủ công bằng:');
        $this->line("  UPDATE purchase_invoices SET invoice_type = '...' WHERE id = ...;");
        $this->line('');
        $this->table(
            ['ID', 'Mã HĐ', 'Ngày HĐ', 'Tổng', 'NCC', 'Line Types', 'Project IDs', 'Nguyên nhân mâu thuẫn'],
            $conflictList->map(fn ($i) => [
                $i['pi']->id,
                $i['pi']->code,
                $i['pi']->invoice_date,
                number_format($i['pi']->total),
                mb_strimwidth($i['pi']->supplier_name, 0, 16, '…'),
                implode(', ', $i['line_types']),
                implode(', ', array_map(fn ($id) => '#' . $id, $i['project_ids'])),
                $i['conflict_reason'],
            ])->all()
        );
    }

    // ─── GL Snapshot ────────────────────────────────────────────────────────

    private function snapshotGl(): array
    {
        $gl156x = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.exclude_from_period_movement', false)
            ->where('jl.account_code', 'LIKE', '156%')
            ->selectRaw('SUM(jl.debit) - SUM(jl.credit) as balance')
            ->value('balance');

        $gl3311 = (float) DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.exclude_from_period_movement', false)
            ->where('jl.account_code', 'LIKE', '331%')
            ->selectRaw('SUM(jl.credit) - SUM(jl.debit) as balance')
            ->value('balance');

        return ['gl_156x' => $gl156x, 'gl_3311' => $gl3311];
    }
}
