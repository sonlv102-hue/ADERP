<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class PurchaseOrderImport implements ToCollection, WithHeadingRow
{
    public array $parsedOrders = [];
    public array $errors       = [];
    public array $warnings     = [];
    public int   $totalRows    = 0;

    private const MAX_ROWS = 500;

    public function __construct(
        private Collection $suppliers,
        private Collection $warehouses,
        private Collection $products,
        private array $existingCodes = []
    ) {
        $this->existingCodes = array_flip($existingCodes);
    }

    public function collection(Collection $rows): void
    {
        if ($rows->count() > self::MAX_ROWS) {
            $this->errors[] = ['row' => 0, 'order_code' => '', 'product_code' => '', 'message' => 'File vượt quá ' . self::MAX_ROWS . ' dòng.'];
            return;
        }

        $this->totalRows = $rows->count();

        foreach ($rows as $index => $row) {
            $this->processRow($index + 2, $row->toArray());
        }
    }

    private function processRow(int $rowNum, array $row): void
    {
        $orderCode     = trim((string)($row['order_code']    ?? ''));
        $productCode   = trim((string)($row['product_code']  ?? ''));
        $supplierCode  = trim((string)($row['supplier_code'] ?? ''));
        $warehouseName = trim((string)($row['warehouse']     ?? ''));
        $orderDate     = trim((string)($row['order_date']    ?? ''));
        $expectedDate  = trim((string)($row['expected_date'] ?? ''));
        $quantity      = $row['quantity']   ?? null;
        $unitPrice     = $row['unit_price'] ?? null;
        $vatRaw        = $row['vat_rate']   ?? null;
        $notes         = trim((string)($row['notes'] ?? ''));

        if ($orderCode === '') {
            $this->errors[] = ['row' => $rowNum, 'order_code' => '', 'product_code' => $productCode, 'message' => 'Mã đơn mua bị trống.'];
            return;
        }

        // ── First occurrence of this order_code: validate header fields ───────
        if (!array_key_exists($orderCode, $this->parsedOrders)) {
            $supplier  = $this->findSupplier($supplierCode);
            $warehouse = $this->findWarehouse($warehouseName);

            $headerErrors = [];
            if (!$supplier)  $headerErrors[] = "Nhà cung cấp \"{$supplierCode}\" không tìm thấy.";
            if (!$warehouse) $headerErrors[] = "Kho \"{$warehouseName}\" không tìm thấy.";
            if ($orderDate === '') $headerErrors[] = 'Ngày đặt bị trống.';

            if ($headerErrors) {
                foreach ($headerErrors as $msg) {
                    $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => '', 'message' => $msg];
                }
                $this->parsedOrders[$orderCode] = ['_invalid' => true];
                return;
            }

            $parsedDate    = $this->parseDate($orderDate);
            $parsedExpDate = $expectedDate !== '' ? $this->parseDate($expectedDate) : null;

            if ($parsedDate === null) {
                $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => '', 'message' => "Ngày đặt \"{$orderDate}\" không hợp lệ (dùng YYYY-MM-DD hoặc DD/MM/YYYY)."];
                $this->parsedOrders[$orderCode] = ['_invalid' => true];
                return;
            }

            $this->parsedOrders[$orderCode] = [
                'code'           => $orderCode,
                'order_date'     => $parsedDate,
                'expected_date'  => $parsedExpDate,
                'supplier_id'    => $supplier->id,
                'supplier_name'  => $supplier->name,
                'warehouse_id'   => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'notes'          => $notes,
                'exists_in_db'   => isset($this->existingCodes[$orderCode]),
                'items'          => [],
            ];
        }

        // Skip item processing for invalid orders
        if (isset($this->parsedOrders[$orderCode]['_invalid'])) {
            return;
        }

        // ── Validate item fields ──────────────────────────────────────────────
        if ($productCode === '') {
            $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => '', 'message' => 'Mã hàng bị trống.'];
            return;
        }

        $product = $this->findProduct($productCode);
        if (!$product) {
            $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => $productCode, 'message' => "Sản phẩm \"{$productCode}\" không tìm thấy."];
            return;
        }

        if (!is_numeric($quantity) || (int)$quantity <= 0) {
            $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => $productCode, 'message' => "Số lượng \"{$quantity}\" không hợp lệ (phải là số nguyên > 0)."];
            return;
        }

        if (!is_numeric($unitPrice) || (float)$unitPrice < 0) {
            $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => $productCode, 'message' => "Đơn giá \"{$unitPrice}\" không hợp lệ (phải >= 0)."];
            return;
        }

        $vatRate = null;
        if ($vatRaw !== null && $vatRaw !== '') {
            if (!is_numeric($vatRaw) || (float)$vatRaw < 0 || (float)$vatRaw > 100) {
                $this->errors[] = ['row' => $rowNum, 'order_code' => $orderCode, 'product_code' => $productCode, 'message' => "Thuế VAT% \"{$vatRaw}\" phải là số từ 0 đến 100."];
                return;
            }
            $vatRate = (float)$vatRaw;
        }

        $qty       = (int)$quantity;
        $price     = (float)$unitPrice;
        $subtotal  = $qty * $price;
        $taxAmount = $vatRate !== null ? round($subtotal * $vatRate / 100, 2) : 0;
        $total     = $subtotal + $taxAmount;

        // Warn on amount mismatch (tolerance ±1 VND for rounding)
        foreach ([
            ['subtotal', 'Thành tiền', $subtotal],
            ['total',    'Tổng sau thuế', $total],
        ] as [$key, $label, $computed]) {
            $excelVal = $row[$key] ?? null;
            if ($excelVal !== null && $excelVal !== '' && is_numeric($excelVal) && abs((float)$excelVal - $computed) > 1) {
                $this->warnings[] = [
                    'row'          => $rowNum,
                    'order_code'   => $orderCode,
                    'product_code' => $productCode,
                    'field'        => $label,
                    'excel'        => (float)$excelVal,
                    'computed'     => $computed,
                ];
            }
        }

        $this->parsedOrders[$orderCode]['items'][] = [
            'row'          => $rowNum,
            'product_id'   => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'unit'         => $product->unit,
            'quantity'     => $qty,
            'unit_price'   => $price,
            'vat_rate'     => $vatRate,
            'subtotal'     => $subtotal,
            'tax_amount'   => $taxAmount,
            'total'        => $total,
        ];
    }

    private function findSupplier(string $codeOrName): ?object
    {
        if ($codeOrName === '') return null;
        return $this->suppliers->firstWhere('code', $codeOrName)
            ?? $this->suppliers->firstWhere('name', $codeOrName);
    }

    private function findWarehouse(string $name): ?object
    {
        if ($name === '') return null;
        return $this->warehouses->firstWhere('name', $name);
    }

    private function findProduct(string $code): ?object
    {
        if ($code === '') return null;
        return $this->products->firstWhere('code', $code);
    }

    private function parseDate(string $raw): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $raw);
            if ($dt && $dt->format($fmt) === $raw) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
