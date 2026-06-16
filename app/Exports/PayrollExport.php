<?php

namespace App\Exports;

use App\Models\Payroll;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PayrollExport implements WithMultipleSheets
{
    use Exportable;

    public function __construct(
        private Payroll $payroll,
        private Collection $items,
    ) {}

    public function sheets(): array
    {
        return [
            new Sheets\PayrollSummarySheet($this->payroll, $this->items),
            new Sheets\PayrollAttendanceSheet($this->payroll, $this->items),
            new Sheets\PayrollJournalSheet($this->payroll, $this->items),
            new Sheets\PayrollAdjustmentSheet($this->payroll, $this->items),
        ];
    }
}
