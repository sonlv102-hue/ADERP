<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

/**
 * Dựng 1 group (1 sản phẩm) cho Sổ chi tiết Nhập-Xuất-Tồn: dòng tồn đầu kỳ,
 * các dòng giao dịch (nhập HOẶC xuất — không ghép cặp), dòng tổng hợp cuối kỳ,
 * và trạng thái âm kho. Tách khỏi InventoryTransactionReportService (chỉ lo
 * query) để giữ mỗi file ≤ 200 dòng.
 */
class InventoryTransactionGroupBuilder
{
    private function describeMovement(object $row): string
    {
        return match (class_basename($row->source_type ?? '')) {
            'StockEntry'              => "Nhập kho {$row->doc_code}",
            'StockExit'               => "Xuất kho {$row->doc_code}",
            'StockTransfer'           => "Chuyển kho {$row->doc_code}",
            'SalesReturn'             => "Trả hàng bán {$row->doc_code}",
            'PurchaseReturn'          => "Trả hàng mua {$row->doc_code}",
            'InventoryCount'          => "Điều chỉnh kiểm kê {$row->doc_code}",
            'InventoryOpeningBalance' => 'Tồn kho đầu kỳ',
            default                   => "{$row->doc_code}",
        };
    }

    // Ngưỡng coi là "ghi nhận lùi ngày": thời điểm ghi nhận trong hệ thống muộn hơn
    // ngày chứng từ quá 3 ngày (loại trừ độ trễ vận hành thông thường tạo/confirm
    // cùng ngày hoặc lệch 1-2 ngày). Xem plans/260729-avco-negative-stock-cost-investigation.
    private const BACKDATE_THRESHOLD_DAYS = 3;

    /** Row mẫu với mọi field mặc định null — override qua $data. 'qty_end'/'value_end' là
     *  TỒN SAU DÒNG NÀY (không phải tồn cuối kỳ, trừ khi row_type='closing') — xem cột "Tồn sau CT". */
    private function row(string $type, string $desc, array $data = []): array
    {
        return array_merge([
            'row_type' => $type, 'description' => $desc,
            'qty_begin' => null, 'value_begin' => null,
            'in_doc_code' => null, 'in_doc_date' => null, 'qty_in' => null, 'value_in' => null,
            'out_doc_code' => null, 'out_doc_date' => null, 'qty_out' => null, 'value_out' => null,
            'qty_end' => null, 'value_end' => null, 'is_negative' => false,
            'estimated_confirmed_at' => null, 'backdated_note' => null,
        ], $data);
    }

    /**
     * Cảnh báo khi thời điểm ghi nhận trong hệ thống muộn hơn ngày chứng từ quá
     * ngưỡng. Dùng `stock_movements.created_at` (tham số $recordedAt) làm mốc —
     * KHÔNG dùng `updated_at` của stock_entries/stock_exits/... vì cột đó có thể
     * bị đổi về sau bởi các thao tác không liên quan (VD: admin sửa ngày xuất kho
     * qua StockExitDateService chỉ đổi exit_date, không đổi stock_movements, nhưng
     * vẫn bump updated_at của stock_exits). `stock_movements.created_at` được ghi
     * đúng 1 lần tại thời điểm confirm và không có nơi nào trong code sửa lại sau
     * đó — xem plans/260729-avco-negative-stock-cost-investigation.
     */
    private function backdatedNote(?string $docDate, ?string $recordedAt): ?string
    {
        if (! $docDate || ! $recordedAt) {
            return null;
        }
        $daysLate = (strtotime($recordedAt) - strtotime($docDate)) / 86400;
        if ($daysLate <= self::BACKDATE_THRESHOLD_DAYS) {
            return null;
        }

        return 'Thời điểm ghi nhận trong hệ thống muộn hơn ngày chứng từ trên '
            . self::BACKDATE_THRESHOLD_DAYS . ' ngày. Đây là dấu hiệu tham khảo do hệ thống '
            . 'chưa lưu thời điểm xác nhận riêng. (Ngày CT: ' . date('d/m/Y', strtotime($docDate))
            . ', ghi nhận lúc: ' . date('d/m/Y H:i', strtotime($recordedAt)) . ')';
    }

    private function stockStatus(bool $everNegative, float $qtyEnd): array
    {
        if ($qtyEnd < 0) {
            return ['code' => 'negative_ending', 'label' => 'Âm kho cuối kỳ', 'color' => 'red'];
        }
        if ($everNegative) {
            return ['code' => 'was_negative', 'label' => 'Âm theo ngày chứng từ', 'color' => 'yellow'];
        }
        return ['code' => 'normal', 'label' => 'Bình thường', 'color' => 'green'];
    }

    /**
     * 1 group = 1 sản phẩm: [dòng tồn đầu kỳ, ...dòng giao dịch, dòng tồn cuối kỳ (luôn có số liệu đầy đủ)]
     */
    public function build(object $product, Collection $movements): array
    {
        $qtyBegin   = (float) $product->qty_begin;
        $valueBegin = (float) $product->value_begin;
        $qty   = $qtyBegin;
        $value = $valueBegin;

        $rows = [$this->row('opening', 'Tồn đầu kỳ', [
            'qty_begin' => $qtyBegin, 'value_begin' => $valueBegin,
            'qty_end' => $qty, 'value_end' => $value, 'is_negative' => $qty < 0,
        ])];

        $totalIn = $totalInValue = $totalOut = $totalOutValue = 0.0;
        $everNegative = $qty < 0;

        foreach ($movements as $m) {
            $mQty = (float) $m->quantity;
            $mVal = (float) $m->amount;
            $qty   += $mQty;
            $value += $mVal;
            $isIn   = $mQty > 0;
            $everNegative = $everNegative || $qty < 0;

            if ($isIn) {
                $totalIn += $mQty;
                $totalInValue += $mVal;
            } else {
                $totalOut += abs($mQty);
                $totalOutValue += abs($mVal);
            }

            $rows[] = $this->row('movement', $this->describeMovement($m), [
                'in_doc_code'  => $isIn ? $m->doc_code : null,
                'in_doc_date'  => $isIn ? $m->doc_date : null,
                'qty_in'       => $isIn ? $mQty : null,
                'value_in'     => $isIn ? $mVal : null,
                'out_doc_code' => $isIn ? null : $m->doc_code,
                'out_doc_date' => $isIn ? null : $m->doc_date,
                'qty_out'      => $isIn ? null : abs($mQty),
                'value_out'    => $isIn ? null : abs($mVal),
                'qty_end' => $qty, 'value_end' => $value, 'is_negative' => $qty < 0,
                'estimated_confirmed_at' => $m->created_at ?? null,
                'backdated_note'         => $this->backdatedNote($m->doc_date ?? null, $m->created_at ?? null),
            ]);
        }

        // Dòng tổng hợp: nhắc lại tồn đầu kỳ (không để trống) + tổng phát sinh + tồn cuối kỳ thực tế
        $rows[] = $this->row('closing', 'Cộng phát sinh + Tồn cuối kỳ', [
            'qty_begin' => $qtyBegin, 'value_begin' => $valueBegin,
            'qty_in' => $totalIn, 'value_in' => $totalInValue,
            'qty_out' => $totalOut, 'value_out' => $totalOutValue,
            'qty_end' => $qty, 'value_end' => $value, 'is_negative' => $qty < 0,
        ]);

        return [
            'product' => [
                'id' => $product->id, 'code' => $product->code, 'name' => $product->name,
                'unit' => $product->unit, 'category' => $product->category ?? '—',
            ],
            'status' => $this->stockStatus($everNegative, $qty),
            'rows' => $rows,
        ];
    }
}
