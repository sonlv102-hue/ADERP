<?php

namespace App\Services;

use App\Exports\EmployeeListExport;
use App\Models\Employee;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class EmployeeExportService
{
    public function exportListExcel(array $filters): EmployeeListExport
    {
        $employees = Employee::filter($filters)->orderBy('name')->get();

        return new EmployeeListExport($employees, $filters);
    }

    public function listExcelFilename(): string
    {
        return 'Danh_sach_nhan_vien_' . Carbon::now()->format('Ymd') . '.xlsx';
    }

    public function exportProfilePdf(Employee $employee)
    {
        return Pdf::loadView('pdf.employee-profile', ['employee' => $this->profileData($employee)]);
    }

    public function renderPrintProfile(Employee $employee): View
    {
        return view('print.employee-profile', ['employee' => $this->profileData($employee)]);
    }

    private function profileData(Employee $employee): array
    {
        $employee->loadMissing('attachments');

        return [
            'model'        => $employee,
            'attachments'  => $employee->attachments,
        ];
    }
}
