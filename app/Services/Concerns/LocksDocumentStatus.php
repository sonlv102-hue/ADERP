<?php

namespace App\Services\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Chống xử lý trùng (bấm đúp / 2 request song song): khóa dòng header chứng từ
 * bằng SELECT ... FOR UPDATE và kiểm tra lại trạng thái MỚI NHẤT trong DB.
 * Bắt buộc gọi bên trong DB::transaction.
 */
trait LocksDocumentStatus
{
    /** @param array<int, BackedEnum|string> $allowed */
    protected function lockAndAssertStatus(Model $document, array $allowed, string $message): void
    {
        $fresh = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->first();
        if ($fresh === null) {
            throw new RuntimeException('Chứng từ không còn tồn tại.');
        }

        $current = $fresh->getAttribute('status');
        $current = $current instanceof BackedEnum ? $current->value : $current;
        $allowedValues = array_map(fn ($s) => $s instanceof BackedEnum ? $s->value : $s, $allowed);

        if (! in_array($current, $allowedValues, true)) {
            throw new RuntimeException($message . ' (trạng thái hiện tại: ' . $current . ')');
        }
    }
}
