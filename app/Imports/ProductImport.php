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

        return new Product([
            'code'        => Product::generateCode(),
            'name'        => $row['name'],
            'category_id' => $category?->id,
            'unit'        => $row['unit'] ?? 'cái',
            'cost_price'  => (float) ($row['cost_price'] ?? 0),
            'sell_price'  => (float) ($row['unit_price'] ?? 0),
            'has_serial'  => strtolower($row['has_serial'] ?? 'no') === 'yes',
            'description' => $row['description'] ?? null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:200',
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
        foreach ($failures as $f) {
            $this->errors[] = "Row {$f->row()}: " . implode(', ', $f->errors());
        }
    }
}
