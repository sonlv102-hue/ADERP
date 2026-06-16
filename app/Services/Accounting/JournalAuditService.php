<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * Rà soát bút toán kế toán — phát hiện thiếu JE, JE sai, tài khoản cha, giá vốn thiếu, v.v.
 * Mỗi finding trả về array chuẩn để hiển thị trên màn hình và artisan command.
 */
class JournalAuditService
{
    public const ERROR_CODES = [
        'E001' => ['label' => 'Thiếu bút toán',                    'severity' => 'critical'],
        'E002' => ['label' => 'Bút toán mất cân bằng Nợ≠Có',       'severity' => 'critical'],
        'E003' => ['label' => 'Hạch toán vào TK tổng hợp',         'severity' => 'critical'],
        'E004' => ['label' => 'HĐ hàng hóa hạch toán sai vào 64x', 'severity' => 'critical'],
        'E005' => ['label' => 'Bút toán trùng (duplicate JE)',      'severity' => 'warning'],
        'E006' => ['label' => 'Chứng từ hủy nhưng JE vẫn posted',  'severity' => 'critical'],
        'E007' => ['label' => 'Thiếu bút toán giá vốn (COGS)',      'severity' => 'critical'],
    ];

    public function run(array $options = []): array
    {
        $from = $options['from'] ?? null;
        $to   = $options['to']   ?? null;

        return array_merge(
            $this->checkMissingJournals($from, $to),
            $this->checkImbalancedJournals($from, $to),
            $this->checkParentAccounts($from, $to),
            $this->checkGoodsInvoiceWrongAccount($from, $to),
            $this->checkDuplicateJournals($from, $to),
            $this->checkCancelledWithActiveJE($from, $to),
            $this->checkMissingCogs($from, $to),
        );
    }

    // ─── E001: Chứng từ confirmed/valid/sent nhưng không có JE ──────────────

    private function checkMissingJournals(?string $from, ?string $to): array
    {
        $findings = [];

        // Hóa đơn bán (sent/overdue/paid) không có JE
        $q = DB::table('invoices as i')
            ->whereIn('i.status', ['sent', 'overdue', 'paid'])
            ->whereNotExists(fn($s) =>
                $s->from('journal_entries as je')
                  ->whereColumn('je.reference_id', 'i.id')
                  ->where('je.reference_type', 'invoice')
                  ->whereIn('je.status', ['posted', 'draft'])
                  ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            )
            ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
            ->select('i.id', 'i.code', 'i.issue_date as doc_date', 'i.total', 'c.name as partner_name');

        if ($from) $q->where('i.issue_date', '>=', $from);
        if ($to)   $q->where('i.issue_date', '<=', $to);

        foreach ($q->get() as $row) {
            $findings[] = $this->finding('E001', 'critical', 'invoice', $row->id, $row->code,
                $row->doc_date, $row->total, $row->partner_name,
                "Hóa đơn bán {$row->code} đã gửi/thanh toán nhưng không có bút toán kế toán.",
                'Mở hóa đơn và xác nhận lại để hệ thống tự sinh bút toán, hoặc tạo bút toán thủ công.'
            );
        }

        // Hóa đơn mua dịch vụ/chi phí (valid/partial_paid/paid) không có JE
        // Hàng hóa + TSCĐ: JE do StockService/FixedAssetService xử lý → không check ở đây
        $inventoryBacked = ['resale_goods', 'raw_material', 'tools_equipment', 'fixed_asset'];

        $q = DB::table('purchase_invoices as pi')
            ->whereIn('pi.status', ['valid', 'partial_paid', 'paid'])
            ->where(fn($q2) =>
                $q2->whereNotIn('pi.invoice_type', $inventoryBacked)
                   ->orWhereNull('pi.invoice_type')
            )
            ->whereNotExists(fn($s) =>
                $s->from('journal_entries as je')
                  ->whereColumn('je.reference_id', 'pi.id')
                  ->where('je.reference_type', 'purchase_invoice')
                  ->whereIn('je.status', ['posted', 'draft'])
                  ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            )
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->select('pi.id', 'pi.code', 'pi.invoice_date as doc_date', 'pi.total',
                     'pi.invoice_type', 's.name as partner_name');

        if ($from) $q->where('pi.invoice_date', '>=', $from);
        if ($to)   $q->where('pi.invoice_date', '<=', $to);

        foreach ($q->get() as $row) {
            $severity  = $row->invoice_type ? 'critical' : 'warning';
            $typeLabel = $row->invoice_type ?? 'chưa phân loại';
            $findings[] = $this->finding('E001', $severity, 'purchase_invoice', $row->id, $row->code,
                $row->doc_date, $row->total, $row->partner_name,
                "HĐ mua ({$typeLabel}) {$row->code} đã duyệt nhưng không có bút toán kế toán.",
                'Mở hóa đơn → chọn đúng Loại HĐ → Xác nhận lại để hệ thống tự sinh bút toán.'
            );
        }

        // Phiếu nhập kho confirmed không có JE
        $q = DB::table('stock_entries as se')
            ->where('se.status', 'confirmed')
            ->whereNotExists(fn($s) =>
                $s->from('journal_entries as je')
                  ->whereColumn('je.reference_id', 'se.id')
                  ->where('je.reference_type', 'stock_entry')
                  ->where('je.status', 'posted')
                  ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            )
            ->select('se.id', 'se.code', 'se.entry_date as doc_date',
                DB::raw('(SELECT COALESCE(SUM(sei.quantity * sei.unit_price), 0) FROM stock_entry_items sei WHERE sei.stock_entry_id = se.id) as total'));

        if ($from) $q->where('se.entry_date', '>=', $from);
        if ($to)   $q->where('se.entry_date', '<=', $to);

        foreach ($q->get() as $row) {
            $findings[] = $this->finding('E001', 'critical', 'stock_entry', $row->id, $row->code,
                $row->doc_date, $row->total, null,
                "Phiếu nhập kho {$row->code} đã xác nhận nhưng không có bút toán nhập kho.",
                'Kiểm tra log lỗi AccountingService::tryPost(). Thường do thiếu account_code trên supplier hoặc product.'
            );
        }

        return $findings;
    }

    // ─── E002: Bút toán mất cân bằng Nợ ≠ Có ───────────────────────────────

    private function checkImbalancedJournals(?string $from, ?string $to): array
    {
        $q = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->whereIn('je.status', ['posted', 'draft'])
            ->select(
                'je.id', 'je.code', 'je.entry_date', 'je.status',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'),
                DB::raw('ABS(SUM(jel.debit) - SUM(jel.credit)) as imbalance')
            )
            ->groupBy('je.id', 'je.code', 'je.entry_date', 'je.status')
            ->havingRaw('ABS(SUM(jel.debit) - SUM(jel.credit)) > 1');

        if ($from) $q->where('je.entry_date', '>=', $from);
        if ($to)   $q->where('je.entry_date', '<=', $to);

        return collect($q->get())->map(fn($row) =>
            $this->finding('E002', 'critical', 'journal_entry', $row->id, $row->code,
                $row->entry_date, max($row->total_debit, $row->total_credit), null,
                "Bút toán {$row->code}: Nợ " . number_format($row->total_debit, 0, ',', '.') .
                " ≠ Có " . number_format($row->total_credit, 0, ',', '.') .
                " (lệch " . number_format($row->imbalance, 0, ',', '.') . " đ).",
                'Vào chi tiết bút toán → kiểm tra và sửa dòng bị thiếu.'
            )
        )->toArray();
    }

    // ─── E003: Hạch toán vào TK tổng hợp (is_detail = false) ───────────────

    private function checkParentAccounts(?string $from, ?string $to): array
    {
        $q = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('account_codes as ac', 'ac.code', '=', 'jel.account_code')
            ->where('je.status', 'posted')
            ->where('ac.is_detail', false)
            ->select('je.id', 'je.code', 'je.entry_date', 'jel.account_code', 'ac.name as account_name')
            ->distinct();

        if ($from) $q->where('je.entry_date', '>=', $from);
        if ($to)   $q->where('je.entry_date', '<=', $to);

        return collect($q->get())->map(fn($row) =>
            $this->finding('E003', 'critical', 'journal_entry', $row->id, $row->code,
                $row->entry_date, null, null,
                "Bút toán {$row->code} hạch toán vào TK tổng hợp {$row->account_code} ({$row->account_name}). Không được phép hạch toán trực tiếp vào TK tổng hợp.",
                "Thay TK {$row->account_code} bằng TK chi tiết cấp cuối tương ứng (ví dụ: 1561, 3311, 1121)."
            )
        )->toArray();
    }

    // ─── E004: HĐ mua hàng hóa nhưng bị hạch toán vào 64x ─────────────────

    private function checkGoodsInvoiceWrongAccount(?string $from, ?string $to): array
    {
        $inventoryTypes = ['resale_goods', 'raw_material', 'tools_equipment'];

        $q = DB::table('purchase_invoices as pi')
            ->whereIn('pi.invoice_type', $inventoryTypes)
            ->join('journal_entries as je', fn($j) =>
                $j->on('je.reference_id', '=', 'pi.id')
                  ->where('je.reference_type', 'purchase_invoice')
                  ->where('je.status', 'posted')
                  ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            )
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('jel.debit', '>', 0)
            ->where(fn($q2) =>
                $q2->where('jel.account_code', 'like', '641%')
                   ->orWhere('jel.account_code', 'like', '642%')
            )
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->select('pi.id', 'pi.code', 'pi.invoice_date as doc_date', 'pi.total',
                     'pi.invoice_type', 's.name as partner_name', 'jel.account_code')
            ->distinct();

        if ($from) $q->where('pi.invoice_date', '>=', $from);
        if ($to)   $q->where('pi.invoice_date', '<=', $to);

        return collect($q->get())->map(fn($row) =>
            $this->finding('E004', 'critical', 'purchase_invoice', $row->id, $row->code,
                $row->doc_date, $row->total, $row->partner_name,
                "HĐ mua hàng hóa {$row->code} (loại: {$row->invoice_type}) hạch toán Nợ TK {$row->account_code} (chi phí) thay vì TK tồn kho. Sai bản chất nghiệp vụ.",
                'Đảo bút toán sai, đổi loại HĐ sang đúng loại và xác nhận lại để StockService sinh bút toán qua phiếu nhập kho.'
            )
        )->toArray();
    }

    // ─── E005: Bút toán trùng cho cùng một chứng từ ─────────────────────────

    private function checkDuplicateJournals(?string $from, ?string $to): array
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';
        $aggFn = $isPostgres
            ? "STRING_AGG(je.code, ', ' ORDER BY je.id)"
            : "GROUP_CONCAT(je.code, ', ')";

        $q = DB::table('journal_entries as je')
            ->where('je.status', 'posted')
            ->whereRaw("je.description NOT LIKE 'Đảo:%'")
            ->whereNotNull('je.reference_type')
            ->whereNotNull('je.reference_id')
            ->select(
                'je.reference_type',
                'je.reference_id',
                DB::raw('COUNT(je.id) as je_count'),
                DB::raw("{$aggFn} as je_codes"),
                DB::raw('MAX(je.entry_date) as last_date')
            )
            ->groupBy('je.reference_type', 'je.reference_id')
            ->havingRaw('COUNT(je.id) > 1');

        if ($from) $q->where('je.entry_date', '>=', $from);
        if ($to)   $q->where('je.entry_date', '<=', $to);

        return collect($q->get())->map(fn($row) =>
            $this->finding('E005', 'warning', $row->reference_type, $row->reference_id, null,
                $row->last_date, null, null,
                "Chứng từ {$row->reference_type} #{$row->reference_id} có {$row->je_count} bút toán posted: {$row->je_codes}. Nguy cơ hạch toán trùng.",
                'Kiểm tra từng bút toán, giữ bút toán đúng và Đảo các bút toán trùng còn lại.'
            )
        )->toArray();
    }

    // ─── E006: Chứng từ đã hủy nhưng JE vẫn posted ──────────────────────────

    private function checkCancelledWithActiveJE(?string $from, ?string $to): array
    {
        $findings = [];
        $cases = [
            ['table' => 'invoices',          'type' => 'invoice',          'date_col' => 'issue_date',   'status_col' => 'status', 'cancelled' => 'cancelled', 'total_col' => 'total'],
            ['table' => 'purchase_invoices', 'type' => 'purchase_invoice', 'date_col' => 'invoice_date', 'status_col' => 'status', 'cancelled' => 'cancelled', 'total_col' => 'total'],
            ['table' => 'stock_entries',     'type' => 'stock_entry',      'date_col' => 'entry_date',   'status_col' => 'status', 'cancelled' => 'cancelled', 'total_col' => null],
            ['table' => 'stock_exits',       'type' => 'stock_exit',       'date_col' => 'exit_date',    'status_col' => 'status', 'cancelled' => 'cancelled', 'total_col' => null],
        ];

        foreach ($cases as $case) {
            $totalExpr = $case['total_col'] ? "doc.{$case['total_col']}" : '0';

            $q = DB::table("{$case['table']} as doc")
                ->where("doc.{$case['status_col']}", $case['cancelled'])
                ->whereExists(fn($s) =>
                    $s->from('journal_entries as je')
                      ->whereColumn('je.reference_id', 'doc.id')
                      ->where('je.reference_type', $case['type'])
                      ->where('je.status', 'posted')
                      ->whereRaw("je.description NOT LIKE 'Đảo:%'")
                )
                ->select('doc.id', 'doc.code',
                    DB::raw("doc.{$case['date_col']} as doc_date"),
                    DB::raw("{$totalExpr} as total"));

            if ($from) $q->where("doc.{$case['date_col']}", '>=', $from);
            if ($to)   $q->where("doc.{$case['date_col']}", '<=', $to);

            $label = match($case['type']) {
                'invoice'          => 'Hóa đơn bán',
                'purchase_invoice' => 'HĐ mua',
                'stock_entry'      => 'Phiếu nhập kho',
                'stock_exit'       => 'Phiếu xuất kho',
                default            => $case['type'],
            };

            foreach ($q->get() as $row) {
                $findings[] = $this->finding('E006', 'critical', $case['type'], $row->id, $row->code,
                    $row->doc_date, $row->total, null,
                    "{$label} {$row->code} đã HỦY nhưng bút toán kế toán vẫn ở trạng thái Posted.",
                    "Vào bút toán liên quan của chứng từ này → chọn Đảo (Reverse) để xóa ảnh hưởng lên sổ cái."
                );
            }
        }

        return $findings;
    }

    // ─── E007: Hóa đơn bán hàng hóa nhưng thiếu phiếu xuất kho (không có COGS) ─

    private function checkMissingCogs(?string $from, ?string $to): array
    {
        $q = DB::table('invoices as i')
            ->whereIn('i.status', ['sent', 'overdue', 'paid'])
            ->whereNotNull('i.order_id')
            ->whereExists(fn($s) =>
                // order có ít nhất 1 product dạng hàng hóa
                $s->from('order_items as oi')
                  ->join('products as p', 'p.id', '=', 'oi.product_id')
                  ->whereColumn('oi.order_id', 'i.order_id')
                  ->whereNotNull('oi.product_id')
                  ->where('p.item_type', 'goods')
                  ->select(DB::raw('1'))
            )
            ->whereNotExists(fn($s) =>
                // không có phiếu XK confirmed cho order này
                $s->from('stock_exits as se')
                  ->whereColumn('se.order_id', 'i.order_id')
                  ->where('se.status', 'confirmed')
                  ->select(DB::raw('1'))
            )
            ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
            ->select('i.id', 'i.code', 'i.issue_date as doc_date', 'i.total', 'c.name as partner_name');

        if ($from) $q->where('i.issue_date', '>=', $from);
        if ($to)   $q->where('i.issue_date', '<=', $to);

        return collect($q->get())->map(fn($row) =>
            $this->finding('E007', 'critical', 'invoice', $row->id, $row->code,
                $row->doc_date, $row->total, $row->partner_name,
                "Hóa đơn {$row->code} bán hàng hóa nhưng không có phiếu xuất kho xác nhận. Giá vốn hàng bán (Dr 632 / Cr 1561) chưa được ghi nhận.",
                'Tạo và xác nhận phiếu xuất kho cho đơn hàng tương ứng để sinh bút toán COGS.'
            )
        )->toArray();
    }

    // ─── Helper ──────────────────────────────────────────────────────────────

    private function finding(
        string  $errorCode,
        string  $severity,
        string  $documentType,
        ?int    $documentId,
        ?string $documentCode,
        ?string $documentDate,
        mixed   $documentAmount,
        ?string $partnerName,
        string  $description,
        string  $suggestedAction,
    ): array {
        return [
            'error_code'       => $errorCode,
            'error_label'      => self::ERROR_CODES[$errorCode]['label'] ?? $errorCode,
            'severity'         => $severity,
            'document_type'    => $documentType,
            'document_id'      => $documentId,
            'document_code'    => $documentCode,
            'document_date'    => $documentDate,
            'document_amount'  => $documentAmount !== null ? (float) $documentAmount : null,
            'partner_name'     => $partnerName,
            'description'      => $description,
            'suggested_action' => $suggestedAction,
        ];
    }
}
