<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductCategory;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Validators\Failure;

class ProductImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnError, SkipsOnFailure, WithLimit
{
    public array $errors = [];
    public int $imported = 0;

    public function model(array $row): ?Product
    {
        $category = ProductCategory::where('name', $row['category'] ?? '')->first();
        $this->imported++;

        $vatRaw = $row['vat_percent'] ?? null;
        $vat    = is_numeric($vatRaw) ? (float) $vatRaw : null;

        return new Product([
            'code'        => Product::generateCode(),
            'name'        => $row['name'],
            'category_id' => $category?->id,
            'unit'        => $row['unit'] ?? 'cái',
            'cost_price'  => (float) ($row['cost_price'] ?? 0),
            'sell_price'  => (float) ($row['unit_price'] ?? 0),
            'vat_percent' => $vat,
            'has_serial'  => strtolower($row['has_serial'] ?? 'no') === 'yes',
            'description' => $row['description'] ?? null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:255',
            'unit_price' => 'nullable|numeric|min:0',
        ];
    }

    public function limit(): int
    {
        return 5000;
    }

    public function onError(\Throwable $e): void
    {
        $this->errors[] = $e->getMessage();
    }

    public function onFailure(Failure ...$failures): void
    {
        $labels = [
            'name'       => 'Tên sản phẩm',
            'unit_price' => 'Đơn giá bán',
            'cost_price' => 'Giá vốn',
            'unit'       => 'Đơn vị tính',
        ];

        $maxLengths = [
            'name' => 255,
        ];

        foreach ($failures as $f) {
            $attr = $f->attribute();
            $val  = (string) ($f->values()[$attr] ?? '');
            $len  = mb_strlen($val);
            $label = $labels[$attr] ?? $attr;

            $messages = [];
            foreach ($f->errors() as $error) {
                if (str_contains($error, 'validation.max')) {
                    $max = $maxLengths[$attr] ?? '?';
                    $preview = $len > 60 ? mb_substr($val, 0, 60) . '...' : $val;
                    $messages[] = "{$label} dài {$len} ký tự, vượt quá giới hạn {$max}: \"{$preview}\"";
                } elseif (str_contains($error, 'validation.required')) {
                    $messages[] = "{$label} là bắt buộc.";
                } elseif (str_contains($error, 'validation.numeric')) {
                    $messages[] = "{$label} phải là số.";
                } else {
                    $messages[] = $error;
                }
            }

            $this->errors[] = "Row {$f->row()}: " . implode('; ', $messages);
        }
    }
}
