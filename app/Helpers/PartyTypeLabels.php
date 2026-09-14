<?php

namespace App\Helpers;

/**
 * Nhãn hiển thị cho party_type — dùng chung giữa CompanyCashFlowController (JSON/Inertia
 * DTO) và Export Excel (Phase 1.1), tránh 2 nguồn nhãn có thể lệch nhau khi thêm party_type
 * mới hoặc sửa chữ tiếng Việt.
 */
class PartyTypeLabels
{
    private const LABELS = [
        'customer' => 'Khách hàng',
        'supplier' => 'Nhà cung cấp',
        'employee' => 'Nhân viên',
        'shareholder' => 'Cổ đông',
        'bank' => 'Ngân hàng',
        'other_individual' => 'Cá nhân khác',
        'other_entity' => 'Đơn vị khác',
    ];

    public static function label(?string $type): ?string
    {
        return self::LABELS[$type] ?? null;
    }
}
