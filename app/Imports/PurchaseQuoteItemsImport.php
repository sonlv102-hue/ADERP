<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Đọc danh sách hàng cần mua từ file Excel (Tab 1).
 * Cột: 0 Mã hàng | 1 SL yêu cầu | 2 Quy cách | 3 Ghi chú. Bỏ dòng tiêu đề đầu.
 */
class PurchaseQuoteItemsImport implements ToCollection
{
    /** @var array<int,array{row:int,code:string,qty:string,specification:?string,note:?string}> */
    public array $rows = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows->slice(1)->values() as $i => $row) {
            $cells = array_values((array) $row->toArray());
            $code = trim((string) ($cells[0] ?? ''));
            if ($code === '') {
                continue;
            }
            $this->rows[] = [
                'row'           => $i + 2,
                'code'          => $code,
                'qty'           => trim((string) ($cells[1] ?? '')),
                'specification' => trim((string) ($cells[2] ?? '')) ?: null,
                'note'          => trim((string) ($cells[3] ?? '')) ?: null,
            ];
        }
    }
}
