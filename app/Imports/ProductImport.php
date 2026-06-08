<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductImport implements ToCollection, WithHeadingRow
{
    public array $errors = [];
    public int $created = 0;
    public int $updated = 0;

    private const MAX_ROWS = 5000;

    public function collection(Collection $rows): void
    {
        if ($rows->count() > self::MAX_ROWS) {
            $this->errors[] = "File vượt quá giới hạn " . self::MAX_ROWS . " dòng.";
            return;
        }

        // First pass: validate all rows, collect category names
        $seenCodes     = [];
        $categoryCache = [];

        foreach ($rows as $index => $row) {
            $rowNum  = $index + 2; // row 1 = header
            $rowArr  = $row->toArray();
            $code    = trim((string) ($rowArr['sku'] ?? ''));
            $name    = trim((string) ($rowArr['name'] ?? ''));
            $catName = trim((string) ($rowArr['category'] ?? ''));

            // Validate code (sku)
            if ($code === '') {
                $this->errors[] = "Row {$rowNum}: Mã SP (cột sku) bị trống.";
            } elseif (mb_strlen($code) > 50) {
                $this->errors[] = "Row {$rowNum}: Mã SP \"{$code}\" dài hơn 50 ký tự.";
            } elseif (isset($seenCodes[$code])) {
                $this->errors[] = "Row {$rowNum}: Mã SP \"{$code}\" bị trùng với dòng {$seenCodes[$code]} trong file.";
            } else {
                $seenCodes[$code] = $rowNum;
            }

            // Validate name
            if ($name === '') {
                $this->errors[] = "Row {$rowNum}: Tên sản phẩm là bắt buộc.";
            } elseif (mb_strlen($name) > 255) {
                $len = mb_strlen($name);
                $preview = mb_substr($name, 0, 60) . '...';
                $this->errors[] = "Row {$rowNum}: Tên sản phẩm dài {$len} ký tự, vượt quá giới hạn 255: \"{$preview}\"";
            }

            // Validate category (only if provided)
            if ($catName !== '') {
                if (!isset($categoryCache[$catName])) {
                    $categoryCache[$catName] = ProductCategory::where('name', $catName)->first();
                }
                if ($categoryCache[$catName] === null) {
                    $this->errors[] = "Row {$rowNum}: Danh mục sản phẩm \"{$catName}\" chưa tồn tại trong hệ thống.";
                }
            }

            // Validate numeric fields
            $unitPrice = $rowArr['unit_price'] ?? null;
            if ($unitPrice !== null && $unitPrice !== '' && !is_numeric($unitPrice)) {
                $this->errors[] = "Row {$rowNum}: Đơn giá bán (unit_price) \"{$unitPrice}\" phải là số.";
            }

            $costPrice = $rowArr['cost_price'] ?? null;
            if ($costPrice !== null && $costPrice !== '' && !is_numeric($costPrice)) {
                $this->errors[] = "Row {$rowNum}: Giá vốn (cost_price) \"{$costPrice}\" phải là số.";
            }

            $vatRaw = $rowArr['vat_percent'] ?? null;
            if ($vatRaw !== null && $vatRaw !== '' && !is_numeric($vatRaw)) {
                $this->errors[] = "Row {$rowNum}: VAT% (vat_percent) \"{$vatRaw}\" phải là số.";
            }
        }

        // If any validation errors, abort — do not import anything
        if (!empty($this->errors)) {
            return;
        }

        // Second pass: upsert in a single transaction
        DB::transaction(function () use ($rows, $categoryCache) {
            foreach ($rows as $row) {
                $rowArr  = $row->toArray();
                $code    = trim((string) ($rowArr['sku'] ?? ''));
                $catName = trim((string) ($rowArr['category'] ?? ''));
                $cat     = $catName !== '' ? ($categoryCache[$catName] ?? null) : null;

                $vatRaw = $rowArr['vat_percent'] ?? null;
                $vat    = is_numeric($vatRaw) ? (float) $vatRaw : null;

                $data = [
                    'code'        => $code,
                    'name'        => trim((string) ($rowArr['name'] ?? '')),
                    'category_id' => $cat?->id,
                    'unit'        => trim((string) ($rowArr['unit'] ?? 'cái')) ?: 'cái',
                    'cost_price'  => (float) ($rowArr['cost_price'] ?? 0),
                    'sell_price'  => (float) ($rowArr['unit_price'] ?? 0),
                    'vat_percent' => $vat,
                    'has_serial'  => strtolower(trim((string) ($rowArr['has_serial'] ?? 'no'))) === 'yes',
                    'description' => $rowArr['description'] ?? null,
                ];

                $existing = Product::withTrashed()->where('code', $code)->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($data);
                    $this->updated++;
                } else {
                    Product::create($data);
                    $this->created++;
                }
            }
        });
    }
}
