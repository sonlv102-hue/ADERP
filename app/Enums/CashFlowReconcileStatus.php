<?php

namespace App\Enums;

/**
 * Trạng thái đối soát cho Báo cáo dòng tiền tài khoản công ty (spec §12).
 * KHÔNG lưu cột riêng — luôn tính từ dữ liệu hiện có (BankTransaction::reconcileStatus())
 * để không bao giờ lệch khi field liên quan bị sửa sau.
 */
enum CashFlowReconcileStatus: string
{
    case Unclassified = 'unclassified';
    case PartyIdentified = 'party_identified';
    case Categorized = 'categorized';
    case DocumentLinked = 'document_linked';
    case Completed = 'completed';
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::Unclassified => 'Chưa đối soát',
            self::PartyIdentified => 'Đã xác định đối tượng',
            self::Categorized => 'Đã phân loại',
            self::DocumentLinked => 'Đã liên kết chứng từ',
            self::Completed => 'Hoàn tất',
            // Trạng thái duy nhất dùng NeedsReview hiện tại: đã phân loại "chuyển tiền
            // nội bộ" nhưng chưa cặp đôi được giao dịch đối ứng (xem BankTransaction::reconcileStatus()).
            self::NeedsReview => 'Chuyển nội bộ – chưa có đối ứng',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unclassified => 'gray',
            self::PartyIdentified, self::Categorized => 'yellow',
            self::DocumentLinked => 'blue',
            self::Completed => 'green',
            self::NeedsReview => 'red',
        };
    }
}
