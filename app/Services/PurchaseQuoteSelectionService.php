<?php

namespace App\Services;

use App\Enums\QuoteSelectionReason;
use App\Models\PurchaseQuoteComparisonItem;
use App\Models\PurchaseQuoteSelection;
use App\Models\PurchaseSupplierQuoteLine;
use RuntimeException;

class PurchaseQuoteSelectionService
{
    public function __construct(private PurchaseQuoteComparisonMatrixService $matrixService) {}

    public function setSelection(
        PurchaseQuoteComparisonItem $item,
        int $quoteLineId,
        ?string $reason,
        ?string $note
    ): PurchaseQuoteSelection {
        $line = PurchaseSupplierQuoteLine::with('quote')->findOrFail($quoteLineId);

        if ($line->comparison_item_id !== $item->id) {
            throw new RuntimeException('Dòng báo giá không thuộc mặt hàng này.');
        }
        if (!$line->quote || !$line->quote->is_active_version || $line->quote->comparison_id !== $item->comparison_id) {
            throw new RuntimeException('Báo giá không hợp lệ hoặc không phải phiên bản đang áp dụng.');
        }

        $matrixItem = collect($this->matrixService->buildMatrix($item->comparison, 'net')['items'])
            ->firstWhere('comparison_item_id', $item->id);

        $lowest = $matrixItem['lowest_price'] ?? null;
        $isLowest = $lowest !== null && (float) $line->net_unit_price <= (float) $lowest + 0.001;

        if (!$isLowest) {
            $reason = $reason ?: null;
            if (!$reason || !QuoteSelectionReason::tryFrom($reason)) {
                throw new RuntimeException('Bạn chọn NCC không có giá thấp nhất — vui lòng chọn lý do lựa chọn hợp lệ.');
            }
        }

        $selection = PurchaseQuoteSelection::updateOrCreate(
            ['comparison_item_id' => $item->id],
            [
                'quote_line_id'       => $line->id,
                'supplier_id'         => $line->quote->supplier_id,
                'selected_unit_price' => $line->net_unit_price,
                'is_lowest_price'     => $isLowest,
                'selection_reason'    => $isLowest ? null : $reason,
                'selection_note'      => $isLowest ? null : $note,
                'selected_by'         => auth()->id(),
                'selected_at'         => now(),
            ]
        );

        activity()
            ->performedOn($selection)
            ->withProperties([
                'comparison_item_id' => $item->id,
                'supplier_id'        => $line->quote->supplier_id,
                'is_lowest'          => $isLowest,
                'reason'             => $isLowest ? null : $reason,
            ])
            ->log('Chọn NCC cho mặt hàng so sánh báo giá');

        return $selection;
    }

    public function clearSelection(PurchaseQuoteComparisonItem $item): void
    {
        $item->selection()->delete();
    }
}
