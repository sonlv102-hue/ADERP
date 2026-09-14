<?php

namespace App\Services;

use App\Models\PurchaseQuoteComparison;

class PurchaseQuoteComparisonMatrixService
{
    /**
     * Ma trận so sánh giá theo từng mặt hàng × từng NCC (active version).
     *
     * @param 'net'|'gross' $priceBasis  net = giá sau CK chưa VAT (mặc định); gross = đơn giá gốc
     * @return array<string,mixed>
     */
    public function buildMatrix(PurchaseQuoteComparison $comparison, string $priceBasis = 'net'): array
    {
        $comparison->loadMissing([
            'items.selection',
            'activeQuotes.supplier',
            'activeQuotes.lines',
        ]);

        $suppliers = [];
        $linesByItem = []; // [comparison_item_id][supplier_id] => cell

        foreach ($comparison->activeQuotes as $quote) {
            $suppliers[$quote->supplier_id] = [
                'supplier_id' => $quote->supplier_id,
                'name'        => $quote->supplier->name ?? '—',
                'code'        => $quote->supplier->code ?? '',
                'quote_id'    => $quote->id,
                'quote_no'    => $quote->quote_no,
                'quote_date'  => optional($quote->quote_date)->format('Y-m-d'),
                'version_no'  => $quote->version_no,
                'shipping_fee'=> (float) $quote->shipping_fee,
                'quoted_count'=> $quote->lines->count(),
            ];

            foreach ($quote->lines as $line) {
                $linesByItem[$line->comparison_item_id][$quote->supplier_id] = [
                    'quote_line_id'  => $line->id,
                    'quote_id'       => $quote->id,
                    'supplier_id'    => $quote->supplier_id,
                    'quote_no'       => $quote->quote_no,
                    'version_no'     => $quote->version_no,
                    'quote_date'     => optional($quote->quote_date)->format('Y-m-d'),
                    'unit_price'     => (float) $line->unit_price,
                    'discount_percent' => (float) $line->discount_percent,
                    'net_unit_price' => (float) $line->net_unit_price,
                    'vat_percent'    => (float) $line->vat_percent,
                    'delivery_time'  => $line->delivery_time,
                    'warranty'       => $line->warranty,
                    'note'           => $line->note,
                    'compare_price'  => $priceBasis === 'gross'
                        ? (float) $line->unit_price
                        : (float) $line->net_unit_price,
                ];
            }
        }

        $itemCount = $comparison->items->count();
        foreach ($suppliers as &$s) {
            $s['item_count'] = $itemCount;
        }
        unset($s);

        $items = [];
        foreach ($comparison->items as $item) {
            $cells = $linesByItem[$item->id] ?? [];
            $prices = array_map(fn ($c) => $c['compare_price'], $cells);

            $lowest = !empty($prices) ? min($prices) : null;
            $lowestSupplierId = null;
            if ($lowest !== null) {
                foreach ($cells as $sid => $c) {
                    if ($c['compare_price'] == $lowest) {
                        $lowestSupplierId = $sid;
                        break;
                    }
                }
            }

            $sel = $item->selection;

            $items[] = [
                'comparison_item_id' => $item->id,
                'product_code'       => $item->product_code_snapshot,
                'product_name'       => $item->product_name_snapshot,
                'unit'               => $item->unit_snapshot,
                'specification'      => $item->specification,
                'requested_qty'      => (float) $item->requested_qty,
                'cells'              => $cells,
                'lowest_price'       => $lowest,
                'lowest_supplier_id' => $lowestSupplierId,
                'selection'          => $sel ? [
                    'supplier_id'         => $sel->supplier_id,
                    'quote_line_id'       => $sel->quote_line_id,
                    'is_lowest_price'     => (bool) $sel->is_lowest_price,
                    'selection_reason'    => $sel->selection_reason?->value,
                    'selection_reason_label' => $sel->selection_reason?->label(),
                    'selection_note'      => $sel->selection_note,
                    'selected_unit_price' => (float) $sel->selected_unit_price,
                ] : null,
            ];
        }

        return [
            'price_basis' => $priceBasis,
            'suppliers'   => array_values($suppliers),
            'items'       => $items,
        ];
    }

    /**
     * Tổng hợp phương án lựa chọn + so với phương án giá thấp nhất.
     * So sánh trên giá net (sau CK, chưa VAT) theo mặc định spec §13.
     *
     * @return array<string,mixed>
     */
    public function selectionSummary(PurchaseQuoteComparison $comparison): array
    {
        $matrix = $this->buildMatrix($comparison, 'net');
        $supplierNames = collect($matrix['suppliers'])->keyBy('supplier_id');

        $bySupplier = [];
        $selectionSubtotal = 0.0;
        $selectionVat = 0.0;
        $lowestTotal = 0.0;
        $selectedCount = 0;
        $allLowest = true;
        $itemsWithQuotes = 0;

        foreach ($matrix['items'] as $item) {
            $qty = $item['requested_qty'];

            if ($item['lowest_price'] !== null) {
                $itemsWithQuotes++;
                $lowestTotal += $item['lowest_price'] * $qty;
            }

            $sel = $item['selection'];
            if (!$sel) {
                $allLowest = false;
                continue;
            }

            $selectedCount++;
            $cell = $item['cells'][$sel['supplier_id']] ?? null;
            $net = $cell['net_unit_price'] ?? $sel['selected_unit_price'];
            $vatPct = $cell['vat_percent'] ?? 0;

            $lineSub = $net * $qty;
            $lineVat = $lineSub * $vatPct / 100;
            $selectionSubtotal += $lineSub;
            $selectionVat += $lineVat;

            if (!$sel['is_lowest_price']) {
                $allLowest = false;
            }

            $sid = $sel['supplier_id'];
            $bySupplier[$sid] ??= ['supplier_id' => $sid, 'supplier_name' => $supplierNames[$sid]['name'] ?? '—', 'sku_count' => 0, 'subtotal' => 0.0, 'vat' => 0.0, 'total' => 0.0];
            $bySupplier[$sid]['sku_count']++;
            $bySupplier[$sid]['subtotal'] += $lineSub;
            $bySupplier[$sid]['vat'] += $lineVat;
            $bySupplier[$sid]['total'] += $lineSub + $lineVat;
        }

        foreach ($bySupplier as &$row) {
            $row['subtotal'] = round($row['subtotal'], 2);
            $row['vat'] = round($row['vat'], 2);
            $row['total'] = round($row['total'], 2);
        }
        unset($row);

        $selectionSubtotal = round($selectionSubtotal, 2);
        $lowestTotal = round($lowestTotal, 2);
        $difference = round($selectionSubtotal - $lowestTotal, 2);

        return [
            'by_supplier'         => array_values($bySupplier),
            'selection_subtotal'  => $selectionSubtotal,
            'selection_vat'       => round($selectionVat, 2),
            'selection_total'     => round($selectionSubtotal + $selectionVat, 2),
            'lowest_total'        => $lowestTotal,
            'difference'          => $difference,
            'selected_count'      => $selectedCount,
            'item_count'          => count($matrix['items']),
            'items_with_quotes'   => $itemsWithQuotes,
            'is_all_lowest'       => $allLowest && $selectedCount > 0 && $selectedCount === $itemsWithQuotes,
        ];
    }
}
