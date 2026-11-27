<?php

namespace App\Services\Inventory;

use RuntimeException;

/**
 * A4 — Trả hàng bán/NCC chưa có quy tắc bút toán được phê duyệt.
 * Xác nhận phiếu sẽ cập nhật tồn kho/AVCO nhưng KHÔNG có JE → kho và sổ cái lệch nhau. Vì vậy chặn bước xác nhận
 * (được lập/lưu nháp bình thường) cho tới khi chính sách được duyệt và JE tự động được bổ sung.
 * Không tự sinh JE theo chính sách chưa duyệt.
 *
 * `inventory_integrity.returns.allow_confirm_without_je` chỉ dành cho kiểm thử kỹ thuật logic tồn kho/AVCO —
 * KHÔNG bật trên production.
 */
class ReturnPostingGuard
{
    public function assertConfirmable(string $docLabel): void
    {
        if (config('inventory_integrity.returns.allow_confirm_without_je', false)) {
            return;
        }
        throw new RuntimeException(
            "Chưa thể xác nhận {$docLabel}: chưa có quy tắc bút toán trả hàng được kế toán trưởng phê duyệt (chính sách A4), "
            . 'nếu xác nhận thì tồn kho sẽ thay đổi mà sổ kế toán không được ghi tương ứng. Phiếu vẫn được lưu nháp. '
            . 'Điều kiện: quyết định chủ đề A4 trên báo cáo "Kiểm soát sai lệch tồn kho và kế toán" và bổ sung bút toán tự động.'
        );
    }
}
