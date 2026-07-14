<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\ProfitReportExport;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Reports\ProfitReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfitReportController extends Controller
{
    public function __construct(private ProfitReportService $svc) {}

    public function index(Request $request): Response
    {
        [$from, $to, $period] = $this->resolvePeriod($request);
        $filters = ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];

        return Inertia::render('Reports/Profit/Index', [
            'summary'        => $this->svc->buildSummary($filters),
            'rows'           => $this->svc->buildRowsByPeriod($filters),
            'period'         => $period,
            'filters'        => $request->only(['period_type', 'year', 'month', 'quarter', 'date_from', 'date_to']),
            'availableYears' => $this->availableYears(),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        [$from, $to, $period] = $this->resolvePeriod($request);
        $filters = ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()];
        $company = Setting::getGroup('company');

        return Excel::download(
            new ProfitReportExport(
                $this->svc->buildSummary($filters),
                $this->svc->buildRowsByPeriod($filters),
                $period,
                $company,
            ),
            'Bao_cao_loi_nhuan_' . now()->format('Ymd') . '.xlsx'
        );
    }

    /**
     * Tính date_from/date_to/label từ params period_type/year/month/quarter/date_from/date_to.
     * Không truyền period_type → mặc định 'month' (tháng hiện tại).
     *
     * @return array{0: Carbon, 1: Carbon, 2: array}
     */
    private function resolvePeriod(Request $request): array
    {
        $periodType = $request->input('period_type', 'month');
        $year       = (int) $request->input('year', now()->year);
        $month      = (int) $request->input('month', now()->month);
        $quarter    = (int) $request->input('quarter', (int) ceil(now()->month / 3));

        switch ($periodType) {
            case 'quarter':
                $startMonth = ($quarter - 1) * 3 + 1;
                $from       = Carbon::create($year, $startMonth, 1)->startOfMonth();
                $to         = $from->copy()->addMonths(2)->endOfMonth();
                $roman      = ['I', 'II', 'III', 'IV'][$quarter - 1] ?? (string) $quarter;
                $label      = "Quý {$roman}/{$year}";
                break;

            case 'year':
                $from  = Carbon::create($year, 1, 1)->startOfDay();
                $to    = Carbon::create($year, 12, 31)->endOfDay();
                $label = "Năm {$year}";
                break;

            case 'custom':
                $from  = Carbon::parse($request->input('date_from', now()->startOfMonth()->toDateString()))->startOfDay();
                $to    = Carbon::parse($request->input('date_to', now()->endOfMonth()->toDateString()))->endOfDay();
                $label = 'Từ ' . $from->format('d/m/Y') . ' đến ' . $to->format('d/m/Y');
                break;

            default:
                $periodType = 'month';
                $from  = Carbon::create($year, $month, 1)->startOfMonth();
                $to    = $from->copy()->endOfMonth();
                $label = 'Tháng ' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . "/{$year}";
                break;
        }

        $period = [
            'type'      => $periodType,
            'date_from' => $from->toDateString(),
            'date_to'   => $to->toDateString(),
            'label'     => $label,
        ];

        return [$from, $to, $period];
    }

    private function availableYears(): array
    {
        $current = now()->year;
        return range($current - 3, $current + 1);
    }
}
