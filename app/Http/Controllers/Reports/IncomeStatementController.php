<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\IncomeStatementExport;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Accounting\IncomeStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IncomeStatementController extends Controller
{
    public function __construct(private IncomeStatementService $svc) {}

    public function index(Request $request): Response
    {
        $year    = (int) $request->input('year', now()->year);
        $unit    = $request->input('unit', 'dong');
        $company = Setting::getGroup('company');
        $report  = $this->svc->getReport($year, $unit);

        return Inertia::render('Reports/IncomeStatement/Index', [
            'report'         => $report,
            'company'        => $company,
            'filters'        => $request->only(['year', 'unit']),
            'availableYears' => $this->availableYears(),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $year    = (int) $request->input('year', now()->year);
        $unit    = $request->input('unit', 'dong');
        $company = Setting::getGroup('company');
        $report  = $this->svc->getReport($year, $unit);

        return Excel::download(
            new IncomeStatementExport($report, $company),
            "b02-dnn-{$year}.xlsx"
        );
    }

    public function exportPdf(Request $request)
    {
        $year    = (int) $request->input('year', now()->year);
        $unit    = $request->input('unit', 'dong');
        $company = Setting::getGroup('company');
        $report  = $this->svc->getReport($year, $unit);

        return Pdf::loadView('pdf.b02-dnn', compact('report', 'company', 'year', 'unit'))
            ->setPaper('a4', 'portrait')
            ->stream("b02-dnn-{$year}.pdf");
    }

    public function lineDetail(Request $request)
    {
        $year    = (int) $request->input('year', now()->year);
        $code    = $request->input('code', '');
        $entries = $this->svc->getDetailEntries($code, $year);

        return response()->json(['entries' => $entries, 'code' => $code, 'year' => $year]);
    }

    private function availableYears(): array
    {
        $current = now()->year;
        return range($current - 3, $current + 1);
    }
}
