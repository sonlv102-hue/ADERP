<?php

namespace App\Services\Inventory\Integrity;

/**
 * Danh mục quy tắc phát hiện sai lệch (phiên bản VERSION). Mỗi quy tắc: nhóm, phân loại mặc định,
 * mức độ, điều kiện, công thức, nguồn dữ liệu, phạm vi. implemented=false → hiển thị "chưa triển khai",
 * KHÔNG bao giờ được tính là PASS.
 */
class IntegrityRuleCatalog
{
    public const VERSION = '2026.11.27-v6';
    public const EPS_QTY = 0.0005;
    public const EPS_VND = 10;   // chênh ≤ 10đ: làm tròn

    public const RULES = [
        // ── Tồn kho (theo sản phẩm × kho) ─────────────────────────────────────
        'NEGATIVE_STOCK' => ['inventory', 'BUSINESS', 'high', 'Sổ kho hoặc AVCO kỳ vọng âm',
            'Σ movement active < 0 hoặc (sổ kho − lô) < 0', 'stock_movements, project_inventory_lots, inventory_balances', 'sản phẩm × kho'],
        'PHANTOM_STOCK' => ['inventory', 'TECHNICAL', 'high', 'AVCO lớn hơn tồn kỳ vọng (tồn ảo)',
            'AVCO SL − (Σ movement − Σ lô còn) > 0', 'inventory_balances, stock_movements, project_inventory_lots', 'sản phẩm × kho'],
        'QUANTITY_MISMATCH' => ['inventory', 'TECHNICAL', 'high', 'AVCO nhỏ hơn tồn kỳ vọng (có hàng không xuất được)',
            'AVCO SL − (Σ movement − Σ lô còn) < 0', 'inventory_balances, stock_movements, project_inventory_lots', 'sản phẩm × kho'],
        'MISSING_BALANCE' => ['inventory', 'TECHNICAL', 'medium', 'Có tồn kho chung nhưng chưa có dòng AVCO',
            'inventory_balances không có dòng và (Σ movement − Σ lô) ≠ 0', 'inventory_balances, stock_movements', 'sản phẩm × kho'],
        'AVAILABILITY_MISMATCH' => ['inventory', 'TECHNICAL', 'high', 'Tồn khả dụng (dùng cho form xuất/backend) khác tồn kỳ vọng, hoặc SP ngừng HĐ còn tồn',
            'fallback Σ movement không dự án ≠ (sổ kho − lô)', 'stock_movements, project_inventory_lots, products', 'sản phẩm × kho'],
        'VALUE_MISMATCH' => ['inventory', 'ACCOUNTING', 'medium', 'Giá trị AVCO khác giá trị sổ kho cùng phạm vi',
            'AVCO GT − (Σ amount movement − Σ GT lô) > 10đ, còn tồn', 'inventory_balances, stock_movements, project_inventory_lots', 'sản phẩm × kho'],
        'COST_MISMATCH' => ['inventory', 'TECHNICAL', 'medium', 'Giá vốn phiếu xuất khác giá trị movement, hoặc SL×giá BQ ≠ GT AVCO',
            '|Σ total_cost dòng xuất + Σ amount movement| > 10đ; |qty×avg_cost − value| > 10đ', 'stock_exit_items, stock_movements, inventory_balances', 'chứng từ xuất / sản phẩm × kho'],
        'ALLOCATION_MISMATCH' => ['inventory', 'TECHNICAL', 'high', 'Lô đã xuất ≠ allocation hiệu lực, hoặc allocation thuộc phiếu không còn xác nhận',
            'Σ issued_qty lô − Σ allocated_qty (voided_at null) ≠ 0', 'project_inventory_lots, stock_exit_item_lot_allocations', 'sản phẩm × kho / phiếu xuất'],
        // ── Chứng từ ─────────────────────────────────────────────────────────
        'DOCUMENT_SEQUENCE_MISMATCH' => ['document', 'BUSINESS', 'low', 'Xuất trước nhập: tồn lũy kế theo thời điểm ghi sổ có lúc âm dù tồn cuối ≥ 0',
            'min(Σ lũy kế movement theo created_at) < 0 và tồn cuối ≥ 0', 'stock_movements', 'sản phẩm × kho'],
        'DOCUMENT_STATUS_MISMATCH' => ['document', 'TECHNICAL', 'high', 'Chứng từ nháp/hủy/đã xóa nhưng còn movement hiệu lực',
            'Σ movement active của chứng từ ≠ 0 khi status ≠ confirmed', 'stock_movements, stock_entries, stock_exits', 'chứng từ'],
        'DUPLICATE_TRANSACTION' => ['document', 'TECHNICAL', 'high', 'Movement/lô ghi trùng cho cùng chứng từ hoặc dòng hàng',
            '|Σ movement chứng từ| > |SL chứng từ|; >1 lô hiệu lực / dòng nhập', 'stock_movements, project_inventory_lots', 'chứng từ'],
        'MISSING_DOCUMENT_LINK' => ['document', 'BUSINESS', 'low', 'Movement trỏ tới dòng hàng không còn tồn tại và không có snapshot',
            'source_item_id không có trong *_items và không có activity_log stock_entry_item', 'stock_movements, stock_entry_items, stock_exit_items, activity_log', 'chứng từ'],
        // ── Kế toán ──────────────────────────────────────────────────────────
        'MISSING_ACCOUNTING_ENTRY' => ['accounting', 'ACCOUNTING', 'high', 'Chứng từ đã xác nhận có giá trị kho nhưng không có JE TK156 liên kết',
            'Σ amount movement ≠ 0 và Σ JE 156 (posted, reference) = 0, không có JE thủ công tham chiếu', 'stock_movements, journal_entries', 'chứng từ'],
        'INVENTORY_GL_MISMATCH' => ['accounting', 'ACCOUNTING', 'medium', 'Giá trị movement của chứng từ ≠ JE TK156; hoặc tổng TK156 ≠ tổng sổ kho',
            '|Σ amount movement − Σ(Nợ−Có) 156 JE posted| > 10đ', 'stock_movements, journal_entry_lines', 'chứng từ / toàn công ty'],
        'PROJECT_WIP_MISMATCH' => ['accounting', 'ACCOUNTING', 'medium', 'Giá vốn xuất dự án ≠ WIP (TK154) ghi nhận',
            '|Σ total_cost phiếu xuất project_cost − Σ project_wip_entries active| > 10đ', 'stock_exit_items, project_wip_entries', 'phiếu xuất dự án'],
        'ZERO_QTY_WITH_VALUE' => ['accounting', 'ACCOUNTING', 'medium', 'Hết hàng nhưng sổ kho/TK156 còn giá trị',
            'SL kỳ vọng = 0, AVCO SL = 0, |GT sổ kho − GT lô| > 10đ', 'stock_movements, inventory_balances', 'sản phẩm × kho'],
        'MANUAL_JE_REVIEW' => ['accounting', 'ACCOUNTING', 'low', 'Chứng từ hạch toán bằng JE thủ công không liên kết',
            'Không có JE tự động; có JE thủ công posted nêu mã chứng từ, ghi TK156', 'journal_entries, journal_entry_lines', 'chứng từ'],
        'ACCOUNT_MAPPING_MISMATCH' => ['accounting', 'ACCOUNTING', 'medium', 'Phiếu xuất dự án không ghi Nợ 154, hoặc xuất bán ghi Nợ 154',
            'JE posted phiếu xuất: project_cost thiếu Nợ 154%; sale_delivery có Nợ 154%', 'stock_exits, journal_entry_lines', 'phiếu xuất'],
        // ── Phân loại bổ sung ────────────────────────────────────────────────
        'EXPECTED_DIFFERENCE' => ['classification', 'EXPECTED', 'info', 'Chênh lệch do làm tròn (≤ 10đ)',
            '0 < |chênh GT| ≤ 10đ', 'inventory_balances, stock_movements', 'sản phẩm × kho'],
        'POLICY_DECISION' => ['classification', 'POLICY_DECISION', 'medium', 'Chủ đề chính sách kế toán chờ quyết định (không phải sai lệch dữ liệu)',
            'Theo danh mục chủ đề chính sách (config/inventory_integrity.php)', 'config', 'toàn hệ thống'],
        'RETURN_WITHOUT_JE' => ['accounting', 'POLICY_DECISION', 'medium', 'Phiếu trả hàng đã xác nhận chưa có bút toán (chờ chính sách A4)',
            'sales_returns/purchase_returns confirmed và không có JE reference', 'sales_returns, purchase_returns, journal_entries', 'chứng từ trả hàng'],
    ];

    /** LEGACY_DATA, UNRESOLVED, PENDING_EVIDENCE là nhãn/phân loại, không phải quy tắc phát hiện riêng. */
    public const TAGS = ['LEGACY_DATA', 'UNRESOLVED'];

    public static function meta(string $code): array
    {
        [$group, $category, $severity, $condition, $formula, $sources, $scope] = self::RULES[$code];
        return compact('group', 'category', 'severity', 'condition', 'formula', 'sources', 'scope') + ['code' => $code, 'implemented' => true];
    }

    public static function all(): array
    {
        return array_map(fn ($c) => self::meta($c), array_keys(self::RULES));
    }
}
