<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SmallToolImport implements ToCollection, WithHeadingRow
{
    public array $parsedTools = [];
    public array $errors      = [];
    public array $warnings    = [];
    public int   $totalRows   = 0;

    private const MAX_ROWS = 500;

    public function __construct(
        private Collection $categories,
        private Collection $employees,
        private Collection $warehouses,
        private Collection $projects,
        private Collection $suppliers,
    ) {}

    public function collection(Collection $rows): void
    {
        if ($rows->count() > self::MAX_ROWS) {
            $this->errors[] = ['row' => 0, 'name' => '', 'message' => 'File vượt quá ' . self::MAX_ROWS . ' dòng.'];
            return;
        }

        $this->totalRows = $rows->count();

        foreach ($rows as $index => $row) {
            $this->processRow($index + 2, $row->toArray());
        }
    }

    private function processRow(int $rowNum, array $row): void
    {
        $name               = trim((string) ($row['name'] ?? ''));
        $categoryCode       = trim((string) ($row['category_code'] ?? ''));
        $unit               = trim((string) ($row['unit'] ?? '')) ?: 'cái';
        $quantityRaw        = $row['quantity'] ?? null;
        $originalCost       = $row['original_cost'] ?? null;
        $vatAmount          = $row['vat_amount'] ?? 0;
        $acquisitionType    = trim((string) ($row['acquisition_type'] ?? '')) ?: 'stock';
        $recognitionMethod  = trim((string) ($row['recognition_method'] ?? '')) ?: 'immediate';
        $allocationPeriods  = $row['allocation_periods'] ?? null;
        $department         = trim((string) ($row['department'] ?? ''));
        $employeeCode       = trim((string) ($row['employee_code'] ?? ''));
        $warehouseName      = trim((string) ($row['warehouse'] ?? ''));
        $projectCode        = trim((string) ($row['project_code'] ?? ''));
        $supplierCode       = trim((string) ($row['supplier_code'] ?? ''));
        $purchaseDate       = trim((string) ($row['purchase_date'] ?? ''));
        $inServiceDate      = trim((string) ($row['in_service_date'] ?? ''));
        $notes              = trim((string) ($row['notes'] ?? ''));

        if ($name === '') {
            $this->errors[] = ['row' => $rowNum, 'name' => '', 'message' => 'Tên CCDC bị trống.'];
            return;
        }

        if (!is_numeric($originalCost) || (float) $originalCost < 0) {
            $this->errors[] = ['row' => $rowNum, 'name' => $name, 'message' => "Nguyên giá \"{$originalCost}\" không hợp lệ (phải là số ≥ 0)."];
            return;
        }

        if ($vatAmount !== '' && $vatAmount !== null && (!is_numeric($vatAmount) || (float) $vatAmount < 0)) {
            $this->errors[] = ['row' => $rowNum, 'name' => $name, 'message' => "VAT \"{$vatAmount}\" không hợp lệ (phải là số ≥ 0)."];
            return;
        }
        $vatAmount = is_numeric($vatAmount) ? (float) $vatAmount : 0;

        if (!in_array($acquisitionType, ['stock', 'direct'], true)) {
            $this->errors[] = ['row' => $rowNum, 'name' => $name, 'message' => "Luồng nghiệp vụ \"{$acquisitionType}\" không hợp lệ (chỉ nhận stock hoặc direct)."];
            return;
        }

        if (!in_array($recognitionMethod, ['immediate', 'allocation'], true)) {
            $this->errors[] = ['row' => $rowNum, 'name' => $name, 'message' => "Ghi nhận chi phí \"{$recognitionMethod}\" không hợp lệ (chỉ nhận immediate hoặc allocation)."];
            return;
        }

        if ($recognitionMethod === 'allocation' && (!is_numeric($allocationPeriods) || (int) $allocationPeriods <= 0)) {
            $this->errors[] = ['row' => $rowNum, 'name' => $name, 'message' => 'Số kỳ phân bổ bắt buộc > 0 khi ghi nhận chi phí = allocation.'];
            return;
        }

        $category  = $categoryCode !== ''  ? $this->categories->firstWhere('code', $categoryCode)   : null;
        $employee  = $employeeCode !== ''  ? $this->employees->firstWhere('code', $employeeCode)     : null;
        $warehouse = $warehouseName !== '' ? $this->warehouses->firstWhere('name', $warehouseName)   : null;
        $project   = $projectCode !== ''   ? $this->projects->firstWhere('code', $projectCode)       : null;
        $supplier  = $supplierCode !== ''  ? $this->suppliers->firstWhere('code', $supplierCode)     : null;

        if ($categoryCode !== '' && !$category) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'category_code', 'message' => "Nhóm \"{$categoryCode}\" không tìm thấy — bỏ trống."];
        }
        if ($employeeCode !== '' && !$employee) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'employee_code', 'message' => "Nhân viên \"{$employeeCode}\" không tìm thấy — bỏ trống."];
        }
        if ($warehouseName !== '' && !$warehouse) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'warehouse', 'message' => "Kho \"{$warehouseName}\" không tìm thấy — bỏ trống."];
        }
        if ($projectCode !== '' && !$project) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'project_code', 'message' => "Dự án \"{$projectCode}\" không tìm thấy — bỏ trống."];
        }
        if ($supplierCode !== '' && !$supplier) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'supplier_code', 'message' => "Nhà cung cấp \"{$supplierCode}\" không tìm thấy — bỏ trống."];
        }

        $parsedPurchaseDate  = $purchaseDate !== ''  ? $this->parseDate($purchaseDate)  : null;
        $parsedInServiceDate = $inServiceDate !== '' ? $this->parseDate($inServiceDate) : null;

        if ($purchaseDate !== '' && $parsedPurchaseDate === null) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'purchase_date', 'message' => "Ngày mua \"{$purchaseDate}\" không đúng định dạng — bỏ trống."];
        }
        if ($inServiceDate !== '' && $parsedInServiceDate === null) {
            $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'in_service_date', 'message' => "Ngày sử dụng \"{$inServiceDate}\" không đúng định dạng — bỏ trống."];
        }

        $qty = 1;
        if ($quantityRaw !== null && $quantityRaw !== '') {
            if (is_numeric($quantityRaw) && (int) $quantityRaw > 0) {
                $qty = (int) $quantityRaw;
            } else {
                $this->warnings[] = ['row' => $rowNum, 'name' => $name, 'field' => 'quantity', 'message' => "Số lượng \"{$quantityRaw}\" không hợp lệ — dùng mặc định 1."];
            }
        }

        $this->parsedTools[] = [
            'row'                 => $rowNum,
            'name'                => $name,
            'category_id'         => $category?->id,
            'category_name'       => $category?->name,
            'unit'                => $unit,
            'quantity'            => $qty,
            'original_cost'       => (float) $originalCost,
            'vat_amount'          => $vatAmount,
            'total_cost'          => (float) $originalCost + $vatAmount,
            'acquisition_type'    => $acquisitionType,
            'recognition_method'  => $recognitionMethod,
            'allocation_periods'  => $recognitionMethod === 'allocation' ? (int) $allocationPeriods : null,
            'department'          => $department ?: null,
            'responsible_employee_id'   => $employee?->id,
            'responsible_employee_name' => $employee?->name,
            'warehouse_id'        => $warehouse?->id,
            'warehouse_name'      => $warehouse?->name,
            'project_id'          => $project?->id,
            'project_name'        => $project?->name,
            'supplier_id'         => $supplier?->id,
            'supplier_name'       => $supplier?->name,
            'purchase_date'       => $parsedPurchaseDate,
            'in_service_date'     => $parsedInServiceDate,
            'notes'               => $notes ?: null,
        ];
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
