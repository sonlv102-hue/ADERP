<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Parse + validate 1 file báo giá NCC theo layout mẫu cố định (đọc theo vị trí cột).
 * Không ghi DB — chỉ trả rows/errors/warnings cho service.
 *
 * Layout mẫu:
 *   Dòng 1 = dấu nhận diện: A1 = "MiniERP Supplier Quote", B1 = "Version: 1"
 *   Dòng 2 = tiêu đề cột
 *   Dòng 3+ = dữ liệu
 * Cột: 0 Mã hàng | 1 Tên hàng | 2 Quy cách | 3 ĐVT | 4 SL yêu cầu | 5 Đơn giá |
 *      6 CK % | 7 VAT % | 8 Thời gian giao | 9 Bảo hành | 10 Ghi chú
 */
class PurchaseSupplierQuoteImport implements ToCollection
{
    public const MAX_ROWS = 500;
    public const TEMPLATE_MARKER = 'MiniERP Supplier Quote';
    public const TEMPLATE_VERSION = 1;

    public const TEMPLATE_ERROR = 'File không đúng mẫu báo giá MiniERP hoặc phiên bản mẫu không được hỗ trợ. '
        . 'Vui lòng dùng nút "Xuất mẫu báo giá" để tải lại file mẫu.';

    private const VALID_VAT = [0, 5, 8, 10];

    /** @var array<int,array<string,mixed>> dòng hợp lệ / cảnh báo, dùng để lưu */
    public array $rows = [];
    /** @var array<int,array{row:int,product_code:string,message:string}> */
    public array $errors = [];
    /** @var array<int,array{row:int,product_code:string,message:string}> */
    public array $warnings = [];
    public int $totalRows = 0;
    public bool $hasFatalError = false;
    public bool $templateMismatch = false;

    /**
     * @param array<string,array{comparison_item_id:int,product_id:int,unit_snapshot:?string,requested_qty:float}> $itemsByCode  key = mã SP đã normalize
     * @param array<string,bool> $knownProductCodes  key = mã SP đã normalize (toàn bộ SP active)
     */
    public function __construct(
        private array $itemsByCode,
        private array $knownProductCodes,
    ) {}

    public function collection(Collection $rows): void
    {
        // Dòng 1: dấu nhận diện template
        $markerRow = array_values((array) ($rows->first()?->toArray() ?? []));
        if (!$this->templateMatches($markerRow)) {
            $this->templateMismatch = true;
            $this->hasFatalError = true;
            $this->errors[] = ['row' => 1, 'product_code' => '', 'message' => self::TEMPLATE_ERROR];
            return;
        }

        // Dòng 1 (marker) + dòng 2 (tiêu đề) → bỏ; dữ liệu bắt đầu dòng 3
        $dataRows = $rows->slice(2)->values();

        if ($dataRows->count() > self::MAX_ROWS) {
            $this->errors[] = ['row' => 0, 'product_code' => '', 'message' => 'File vượt quá ' . self::MAX_ROWS . ' dòng.'];
            $this->hasFatalError = true;
            return;
        }

        $seen = [];
        foreach ($dataRows as $i => $row) {
            $this->processRow($i + 3, array_values((array) $row->toArray()), $seen);
        }

        $this->totalRows = count($this->rows) + $this->countErrorRows();
    }

    /** Dòng 1: A1 chứa marker, B1 chứa "Version: N" khớp TEMPLATE_VERSION. */
    private function templateMatches(array $markerRow): bool
    {
        $a = mb_strtolower(trim((string) ($markerRow[0] ?? '')));
        if (!str_contains($a, mb_strtolower(self::TEMPLATE_MARKER))) {
            return false;
        }
        $b = (string) ($markerRow[1] ?? '');
        if (!preg_match('/(\d+)/', $b, $m)) {
            return false;
        }

        return (int) $m[1] === self::TEMPLATE_VERSION;
    }

    private function processRow(int $rowNum, array $cells, array &$seen): void
    {
        $code = trim((string) ($cells[0] ?? ''));
        if ($code === '' && trim(implode('', array_map('strval', $cells))) === '') {
            return; // dòng trống hoàn toàn
        }

        $norm = $this->norm($code);

        if ($code === '') {
            $this->fatal($rowNum, '', 'Thiếu Mã hàng.');
            return;
        }

        // V03 — trùng mã trong cùng file
        if (isset($seen[$norm])) {
            $this->fatal($rowNum, $code, "Mã hàng \"{$code}\" bị trùng trong file (đã xuất hiện ở dòng {$seen[$norm]}).");
            return;
        }
        $seen[$norm] = $rowNum;

        // V01 / V02
        if (!isset($this->itemsByCode[$norm])) {
            $msg = isset($this->knownProductCodes[$norm])
                ? "Mã hàng \"{$code}\" không thuộc đợt so sánh này."     // V02
                : "Mã hàng \"{$code}\" không tồn tại trong hệ thống.";   // V01
            $this->fatal($rowNum, $code, $msg);
            return;
        }
        $item = $this->itemsByCode[$norm];

        // V04 / V05 / V06 — đơn giá
        $rawPrice = $cells[5] ?? null;
        $price = $this->parseNumber($rawPrice);
        if ($price === null) {
            $this->fatal($rowNum, $code, "Đơn giá \"{$rawPrice}\" không phải là số.");
            return;
        }
        if ($price < 0) {
            $this->fatal($rowNum, $code, 'Đơn giá không được âm.');
            return;
        }

        // V07 — chiết khấu
        $discount = $this->parseNumber($cells[6] ?? null) ?? 0.0;
        if ($discount < 0 || $discount > 100) {
            $this->fatal($rowNum, $code, "Chiết khấu \"{$discount}\" phải trong khoảng 0–100%.");
            return;
        }

        // V08 — VAT
        $vat = $this->parseNumber($cells[7] ?? null) ?? 0.0;
        if (!in_array((int) $vat, self::VALID_VAT, true) && ($vat < 0 || $vat > 100)) {
            $this->fatal($rowNum, $code, "Thuế VAT \"{$vat}\" không hợp lệ.");
            return;
        }

        // V06 — cảnh báo giá 0
        if ($price == 0.0) {
            $this->warnings[] = ['row' => $rowNum, 'product_code' => $code, 'message' => 'Đơn giá bằng 0 — vui lòng kiểm tra lại.'];
        }

        // V09 — ĐVT lệch (cảnh báo, không chặn)
        $fileUnit = trim((string) ($cells[3] ?? ''));
        if ($fileUnit !== '' && $item['unit_snapshot'] && $this->norm($fileUnit) !== $this->norm($item['unit_snapshot'])) {
            $this->warnings[] = [
                'row' => $rowNum,
                'product_code' => $code,
                'message' => "ĐVT trong file (\"{$fileUnit}\") khác ĐVT yêu cầu (\"{$item['unit_snapshot']}\").",
            ];
        }

        $net = round($price * (1 - $discount / 100), 2);
        $qty = (float) $item['requested_qty'];
        $subtotal = round($qty * $net, 2);

        $this->rows[] = [
            'row'                => $rowNum,
            'comparison_item_id' => $item['comparison_item_id'],
            'product_id'         => $item['product_id'],
            'product_code'       => $code,
            'unit_snapshot'      => $item['unit_snapshot'],
            'requested_qty'      => $qty,
            'unit_price'         => $price,
            'discount_percent'   => $discount,
            'net_unit_price'     => $net,
            'vat_percent'        => $vat,
            'delivery_time'      => trim((string) ($cells[8] ?? '')) ?: null,
            'warranty'           => trim((string) ($cells[9] ?? '')) ?: null,
            'note'               => trim((string) ($cells[10] ?? '')) ?: null,
            'line_subtotal'      => $subtotal,
            'vat_amount'         => round($subtotal * $vat / 100, 2),
        ];
    }

    private function fatal(int $rowNum, string $code, string $message): void
    {
        $this->errors[] = ['row' => $rowNum, 'product_code' => $code, 'message' => $message];
        $this->hasFatalError = true;
    }

    private function countErrorRows(): int
    {
        return count(array_unique(array_column($this->errors, 'row')));
    }

    private function norm(string $v): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $v)));
    }

    /** Chấp nhận "12000", "12.000", "1.234.567,5", "1,234,567.5". Trả null nếu không phải số. */
    private function parseNumber(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = trim((string) $raw);
        $s = str_replace(' ', '', $s);

        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {          // vi: 1.234.567,89
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {    // en: 1,234,567.89
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
