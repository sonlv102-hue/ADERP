<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\CashFlowStatementExport;
use App\Http\Controllers\Controller;
use App\Services\Accounting\CashFlowStatementService;
use Illuminate\Http\Request;
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
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";

        $statement = $this->svc->build($dateFrom, $dateTo);

        return Inertia::render('Reports/CashFlowStatement/Index', [
            'statement' => $statement,
            'filters'   => $request->only(['year', 'date_from', 'date_to']),
            'dateFrom'  => $dateFrom,
            'dateTo'    => $dateTo,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $year     = (int) $request->input('year', now()->year);
        $dateFrom = $request->input('date_from') ?: "{$year}-01-01";
        $dateTo   = $request->input('date_to')   ?: "{$year}-12-31";

        $statement = $this->svc->build($dateFrom, $dateTo);

        return Excel::download(
            new CashFlowStatementExport($statement),
            "b03-dnn-{$year}.xlsx"
        );
    }
}
