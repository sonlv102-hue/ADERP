<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\BalanceSheetExport;
use App\Http\Controllers\Controller;
use App\Models\BalanceSheetAccountMapping;
use App\Models\Setting;
use App\Services\Accounting\FinancialPositionReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Báo cáo tình hình tài chính — Mẫu B01a-DNN (Thông tư 133/2016/TT-BTC).
 */
class BalanceSheetController extends Controller
{
    public function __construct(
        private readonly FinancialPositionReportService $reportSvc
    ) {}

    public function index(Request $request): Response
    {
        $asOf    = $request->input('as_of', now()->toDateString());
        $mode    = $request->input('mode', 'management');
        $data    = $this->reportSvc->build($asOf, $mode);
        $company = Setting::getGroup('company');

        // Số đầu năm: số dư tại 01/01 của năm hiện tại
        $startOfYear = substr($asOf, 0, 4) . '-01-01';
        $priorData   = $this->reportSvc->build($startOfYear, $mode);
        $priorMap    = collect($priorData['rows'])->keyBy('config_code')->map(fn($r) => $r['amount'] ?? 0.0);

        $rows = array_map(function (array $row) use ($priorMap): array {
            $row['prior_amount'] = $priorMap[$row['config_code']] ?? 0.0;
            return $row;
        }, $data['rows']);

        return Inertia::render('Reports/BalanceSheet/Index', [
            'balanceSheet'     => $rows,
            'summary'          => $data['summary'],
            'warnings'         => $data['warnings'],
            'trialBalance'     => $data['trial_balance'],
            'unmappedAccounts' => $data['unmapped_accounts'],
            'reportMeta'       => [
                'report_code' => $data['report_code'],
                'report_name' => $data['report_name'],
                'circular'    => $data['circular'],
            ],
            'company'               => $company,
            'reportItems'           => $this->getReportItems(),
            'canManageAccounting'   => auth()->user()->can('accounting.manage'),
            'filters'               => ['as_of' => $asOf, 'mode' => $mode],
            'reportMode'            => $data['report_mode'],
            'provisionalPnl'        => $data['provisional_pnl'],
            'unclosedIncomeExpense' => $data['unclosed_income_expense'],
            'glBreakdown'           => $data['gl_breakdown'],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $asOf        = $request->input('as_of', now()->toDateString());
        $mode        = $request->input('mode', 'management');
        $data        = $this->reportSvc->build($asOf, $mode);
        $startOfYear = substr($asOf, 0, 4) . '-01-01';
        $priorData   = $this->reportSvc->build($startOfYear, $mode);

        return Excel::download(
            new BalanceSheetExport($data, $priorData),
            'bcttc-b01a-dnn-' . $asOf . '.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        $asOf        = $request->input('as_of', now()->toDateString());
        $mode        = $request->input('mode', 'management');
        $data        = $this->reportSvc->build($asOf, $mode);
        $company     = Setting::getGroup('company');
        $startOfYear = substr($asOf, 0, 4) . '-01-01';
        $priorData   = $this->reportSvc->build($startOfYear, $mode);
        $priorMap    = collect($priorData['rows'])->keyBy('config_code')->map(fn($r) => $r['amount'] ?? 0.0);

        $rows = array_map(function (array $row) use ($priorMap): array {
            $row['prior_amount'] = $priorMap[$row['config_code']] ?? 0.0;
            return $row;
        }, $data['rows']);

        return Pdf::loadView('pdf.b01a-dnn', compact('rows', 'company', 'asOf', 'data'))
            ->setPaper('a4', 'portrait')
            ->stream('b01a-dnn-' . $asOf . '.pdf');
    }

    public function mapAccount(Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $validItemCodes = $this->getValidItemCodes();

        $data = $request->validate([
            'account_code' => ['required', 'string', 'max:20', Rule::exists('account_codes', 'code')],
            'item_code'    => ['required', 'string', Rule::in($validItemCodes)],
        ]);

        BalanceSheetAccountMapping::updateOrCreate(
            ['account_code' => $data['account_code']],
            ['item_code'    => $data['item_code'], 'created_by' => auth()->id()]
        );

        return back()->with('success', "TK {$data['account_code']} đã được map vào chỉ tiêu {$data['item_code']}.");
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function getReportItems(): array
    {
        $cfg   = config('accounting_reports_tt133');
        $items = [];

        foreach ($cfg['assets'] as $item) {
            if ($item['balance_side'] !== 'formula') {
                $items[] = [
                    'item_code' => $item['item_code'],
                    'item_name' => $item['item_name'],
                    'section'   => 'asset',
                ];
            }
        }
        foreach ($cfg['equity_liabilities'] as $item) {
            if ($item['balance_side'] !== 'formula') {
                $items[] = [
                    'item_code' => $item['item_code'],
                    'item_name' => $item['item_name'],
                    'section'   => 'equity',
                ];
            }
        }

        return $items;
    }

    private function getValidItemCodes(): array
    {
        $cfg      = config('accounting_reports_tt133');
        $allItems = array_merge($cfg['assets'], $cfg['equity_liabilities']);
        return array_column(
            array_filter($allItems, fn($i) => $i['balance_side'] !== 'formula'),
            'item_code'
        );
    }
}
