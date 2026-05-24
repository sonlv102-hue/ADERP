<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TemplateExport implements FromArray, WithHeadings
{
    public function __construct(
        private array $headers,
        private string $sheetName = 'Template'
    ) {}

    public function array(): array
    {
        return [];
    }

    public function headings(): array
    {
        return $this->headers;
    }
}
