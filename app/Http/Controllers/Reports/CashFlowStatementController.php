<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\CashFlowStatementExport;
use App\Http\Controllers\Controller;
use App\Services\Accounting\CashFlowStatementService;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CashFlowStatementController extends Controller
{
    public function __construct(
        private readonly CashFlowStatementService $svc
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('accounting.view');

        $year = (int) $request->input('year', now()->year);
        $unit = $request->input('unit', 'dong');

        $report       = $this->svc->getReport($year, $unit);
        $unclassified = $this->svc->getUnclassifiedCashVouchers($year);
        $company      = Setting::getGroup('company');

        return Inertia::render('Reports/CashFlowStatement/Index', [
            'report'            => $report,
            'unclassifiedCount' => $unclassified->count(),
            'unclassified'      => $unclassified->take(50),
            'company'           => $company,
            'filters'           => ['year' => $year, 'unit' => $unit],
            'availableYears'    => $this->availableYears(),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $this->authorize('accounting.view');

        $year    = (int) $request->input('year', now()->year);
        $unit    = $request->input('unit', 'dong');
        $report  = $this->svc->getReport($year, $unit);
        $company = Setting::getGroup('company');

        return Excel::download(
            new CashFlowStatementExport($report, $company),
            "b03-dnn-{$year}.xlsx"
        );
    }

    public function exportPdf(Request $request): HttpResponse
    {
        $this->authorize('accounting.view');

        $year    = (int) $request->input('year', now()->year);
        $unit    = $request->input('unit', 'dong');
        $report  = $this->svc->getReport($year, $unit);
        $company = Setting::getGroup('company');

        $pdf = Pdf::loadView('pdf.b03-dnn', compact('report', 'company', 'year', 'unit'))
            ->setPaper('a4', 'portrait');

        return $pdf->download("b03-dnn-{$year}.pdf");
    }

    public function lineDetail(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('accounting.view');

        $code = $request->input('code');
        $year = (int) $request->input('year', now()->year);

        $detail = $this->svc->getLineDetail($code, $year);

        return response()->json(['detail' => $detail]);
    }

    public function updateVoucherCode(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'voucher_id'     => ['required', 'integer', 'exists:cash_vouchers,id'],
            'cash_flow_code' => ['nullable', 'string', 'max:5'],
        ]);

        \App\Models\CashVoucher::where('id', $data['voucher_id'])
            ->update(['cash_flow_code' => $data['cash_flow_code'] ?: null]);

        return response()->json(['ok' => true]);
    }

    private function availableYears(): array
    {
        $current = now()->year;
        return range($current, $current - 4);
    }
}
