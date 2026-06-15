<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\BalanceSheetExport;
use App\Http\Controllers\Controller;
use App\Services\Accounting\FinancialPositionReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Báo cáo tình hình tài chính — Mẫu B01a-DNN (Thông tư 133/2016/TT-BTC).
 * Menu vẫn hiển thị "Cân đối kế toán" nhưng logic bên trong theo TT133.
 */
class BalanceSheetController extends Controller
{
    public function __construct(
        private readonly FinancialPositionReportService $reportSvc
    ) {}

    public function index(Request $request): Response
    {
        $asOf = $request->input('as_of', now()->toDateString());
        $data = $this->reportSvc->build($asOf);

        return Inertia::render('Reports/BalanceSheet/Index', [
            'balanceSheet'     => $data['rows'],
            'summary'          => $data['summary'],
            'warnings'         => $data['warnings'],
            'trialBalance'     => $data['trial_balance'],
            'unmappedAccounts' => $data['unmapped_accounts'],
            'reportMeta'       => [
                'report_code' => $data['report_code'],
                'report_name' => $data['report_name'],
                'circular'    => $data['circular'],
            ],
            'filters'          => ['as_of' => $asOf],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $asOf = $request->input('as_of', now()->toDateString());
        $data = $this->reportSvc->build($asOf);

        return Excel::download(
            new BalanceSheetExport($data),
            'bcttc-b01a-dnn-' . $asOf . '.xlsx'
        );
    }
}
