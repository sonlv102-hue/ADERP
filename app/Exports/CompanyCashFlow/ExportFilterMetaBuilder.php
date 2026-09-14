<?php

namespace App\Exports\CompanyCashFlow;

use App\Enums\CashFlowReconcileStatus;
use App\Models\BankAccount;
use App\Models\CashFlowCategory;
use App\Models\Project;
use App\Models\User;

/** Mô tả filter đang áp dụng để in vào sheet 01 (spec §19) — thuần trình bày, không phải business logic. */
class ExportFilterMetaBuilder
{
    public static function build(array $filters, ?User $user): array
    {
        return [
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'bank_account' => isset($filters['bank_account_id'])
                ? (BankAccount::find($filters['bank_account_id'])?->name ?? 'Không xác định')
                : 'Tất cả',
            'project' => isset($filters['project_id'])
                ? (Project::find($filters['project_id'])?->name ?? 'Không xác định')
                : 'Tất cả',
            'category' => self::categoryLabel($filters['cash_flow_category_id'] ?? null),
            'direction' => match ($filters['direction'] ?? null) {
                'in' => 'Tiền vào',
                'out' => 'Tiền ra',
                default => 'Tất cả',
            },
            'reconcile_status' => isset($filters['reconcile_status'])
                ? (CashFlowReconcileStatus::tryFrom($filters['reconcile_status'])?->label() ?? $filters['reconcile_status'])
                : 'Tất cả',
            'exported_by' => $user?->name ?? '',
            'exported_at' => now(),
        ];
    }

    private static function categoryLabel(?string $categoryFilter): string
    {
        if ($categoryFilter === null) {
            return 'Tất cả';
        }
        if ($categoryFilter === 'null') {
            return 'Chưa xác định';
        }

        return CashFlowCategory::find($categoryFilter)?->name ?? 'Không xác định';
    }
}
