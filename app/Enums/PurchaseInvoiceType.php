<?php

namespace App\Enums;

enum PurchaseInvoiceType: string
{
    case ResaleGoods          = 'resale_goods';
    case RawMaterial          = 'raw_material';
    case ToolsEquipment       = 'tools_equipment';
    case ProjectConstruction  = 'project_construction';
    case ExternalService      = 'external_service';
    case SellingExpense       = 'selling_expense';
    case ManagementExpense    = 'management_expense';
    case FixedAsset           = 'fixed_asset';
    case PrepaidExpense       = 'prepaid_expense';

    public function label(): string
    {
        return match($this) {
            self::ResaleGoods         => 'Hàng hóa bán lại',
            self::RawMaterial         => 'Nguyên vật liệu / vật tư',
            self::ToolsEquipment      => 'Công cụ dụng cụ',
            self::ProjectConstruction => 'Thi công dự án',
            self::ExternalService     => 'Dịch vụ mua ngoài',
            self::SellingExpense      => 'Chi phí bán hàng',
            self::ManagementExpense   => 'Chi phí quản lý',
            self::FixedAsset          => 'Tài sản cố định',
            self::PrepaidExpense      => 'Chi phí trả trước',
        };
    }

    /**
     * JE do StockService xử lý tại phiếu nhập kho — invoice không tự tạo JE.
     */
    public function isInventoryBacked(): bool
    {
        return in_array($this, [
            self::ResaleGoods,
            self::RawMaterial,
            self::ToolsEquipment,
        ]);
    }

    /**
     * JE do FixedAssetService xử lý khi "Ghi nhận TSCĐ" — invoice không tự tạo JE.
     */
    public function isFixedAssetBacked(): bool
    {
        return $this === self::FixedAsset;
    }

    /**
     * TK Nợ mặc định khi không có expense_account_code override.
     * Chỉ áp dụng cho loại không phải inventory/fixed_asset.
     */
    public function defaultDebitAccount(): string
    {
        return match($this) {
            self::ProjectConstruction => '154',
            self::SellingExpense      => '6421',
            self::ManagementExpense   => '6422',
            self::PrepaidExpense      => '242',
            self::ExternalService     => '6422',
            default                   => '6422',
        };
    }

    /**
     * TK VAT đầu vào: TSCĐ dùng 1332, tất cả còn lại dùng 1331.
     */
    public function vatInputAccount(): string
    {
        return $this === self::FixedAsset ? '1332' : '1331';
    }

    /**
     * TK Có mặc định khi post JE.
     * 3311 = phải trả NCC hàng hóa; 3312 = phải trả NCC dịch vụ.
     * Inventory-backed và fixed-asset-backed loại không gọi method này
     * (JE do StockService / FixedAssetService xử lý).
     */
    public function defaultCreditAccount(): string
    {
        return match($this) {
            self::ResaleGoods,
            self::RawMaterial,
            self::ToolsEquipment => '3311',
            default              => '3312',
        };
    }

    /**
     * Mapping từ PurchaseOrderItem.line_type → invoice_type mặc định.
     */
    public static function fromLineType(string $lineType): ?self
    {
        return match($lineType) {
            'goods'       => self::ResaleGoods,
            'material'    => self::RawMaterial,
            'tool'        => self::ToolsEquipment,
            'service'     => self::ManagementExpense,
            'fixed_asset' => self::FixedAsset,
            default       => null,
        };
    }
}
