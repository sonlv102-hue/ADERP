<?php

namespace App\Http\Controllers\Reports;

use App\Exports\Reports\IncomeStatementExport;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Accounting\IncomeStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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
        [$from, $to, $period, $comparison] = $this->resolvePeriod($request);
        $unit    = $request->input('unit', 'dong');
        $company = Setting::getGroup('company');
        $report  = $this->svc->getReportForRange($from, $to, $unit, $period, $comparison);

        return Inertia::render('Reports/IncomeStatement/Index', [
            'report'         => $report,
            'company'        => $company,
            'filters'        => $request->only(['period_type', 'year', 'month', 'quarter', 'date_from', 'date_to', 'unit', 'compare_type']),
            'availableYears' => $this->availableYears(),
        ]);
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        [$from, $to, $period, $comparison] = $this->resolvePeriod($request);
        $unit    = $request->input('unit', 'dong');
        $company = Setting::getGroup('company');
        $report  = $this->svc->getReportForRange($from, $to, $unit, $period, $comparison);

        return Excel::download(
            new IncomeStatementExport($report, $company),
            'b02-dnn-' . $this->fileSlug($period) . '.xlsx'
        );
    }

    public function exportPdf(Request $request)
    {
        [$from, $to, $period, $comparison] = $this->resolvePeriod($request);
        $unit    = $request->input('unit', 'dong');
        $company = Setting::getGroup('company');
        $report  = $this->svc->getReportForRange($from, $to, $unit, $period, $comparison);

        return Pdf::loadView('pdf.b02-dnn', compact('report', 'company', 'unit'))
            ->setPaper('a4', 'portrait')
            ->stream('b02-dnn-' . $this->fileSlug($period) . '.pdf');
    }

    public function lineDetail(Request $request)
    {
        $code = $request->input('code', '');

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $from = Carbon::parse($request->input('date_from'))->startOfDay();
            $to   = Carbon::parse($request->input('date_to'))->endOfDay();
        } else {
            $year = (int) $request->input('year', now()->year);
            $from = Carbon::create($year, 1, 1)->startOfDay();
            $to   = Carbon::create($year, 12, 31)->endOfDay();
        }

        $entries = $this->svc->getDetailEntriesForRange($code, $from, $to);

        return response()->json([
            'entries'   => $entries,
            'code'      => $code,
            'date_from' => $from->toDateString(),
            'date_to'   => $to->toDateString(),
        ]);
    }

    /**
     * Tính date_from/date_to/label + comparison_period từ params period_type/year/month/quarter/date_from/date_to/compare_type.
     * Không truyền period_type → mặc định 'year' để tương thích link cũ chỉ có ?year=.
     *
     * @return array{0: Carbon, 1: Carbon, 2: array, 3: ?array}
     */
    private function resolvePeriod(Request $request): array
    {
        $periodType = $request->input('period_type', 'year');
        $year       = (int) $request->input('year', now()->year);

        $month   = (int) $request->input('month', now()->month);
        $quarter = (int) $request->input('quarter', (int) ceil(now()->month / 3));

        switch ($periodType) {
            case 'month':
                $from  = Carbon::create($year, $month, 1)->startOfMonth();
                $to    = $from->copy()->endOfMonth();
                $label = 'Tháng ' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . "/{$year}";
                break;

            case 'quarter':
                $startMonth = ($quarter - 1) * 3 + 1;
                $from       = Carbon::create($year, $startMonth, 1)->startOfMonth();
                $to         = $from->copy()->addMonths(2)->endOfMonth();
                $roman      = ['I', 'II', 'III', 'IV'][$quarter - 1] ?? (string) $quarter;
                $label      = "Quý {$roman}/{$year}";
                break;

            case 'custom':
                $from  = Carbon::parse($request->input('date_from', now()->startOfYear()->toDateString()))->startOfDay();
                $to    = Carbon::parse($request->input('date_to', now()->endOfYear()->toDateString()))->endOfDay();
                $label = 'Từ ' . $from->format('d/m/Y') . ' đến ' . $to->format('d/m/Y');
                break;

            default:
                $periodType = 'year';
                $from       = Carbon::create($year, 1, 1)->startOfDay();
                $to         = Carbon::create($year, 12, 31)->endOfDay();
                $label      = "Năm {$year}";
                break;
        }

        $period = [
            'type'      => $periodType,
            'date_from' => $from->toDateString(),
            'date_to'   => $to->toDateString(),
            'label'     => $label,
        ];

        $comparison = $this->resolveComparison(
            $request->input('compare_type', 'same_period_last_year'),
            $periodType,
            $from,
            $to,
            $year,
            $month,
            $quarter
        );

        return [$from, $to, $period, $comparison];
    }

    private function resolveComparison(string $compareType, string $periodType, Carbon $from, Carbon $to, int $year, int $month, int $quarter): ?array
    {
        if ($compareType === 'none') {
            return null;
        }

        if ($compareType === 'previous_period') {
            return $this->previousCalendarPeriod($periodType, $from, $to, $year, $month, $quarter);
        }

        // Mặc định: same_period_last_year — dùng *NoOverflow để 29/2 → 28/2 năm trước
        // thay vì Carbon mặc định roll-over sang 1/3.
        return [
            'date_from' => $from->copy()->subYearNoOverflow()->toDateString(),
            'date_to'   => $to->copy()->subYearNoOverflow()->toDateString(),
            'label'     => 'Cùng kỳ năm trước',
        ];
    }

    /**
     * "Kỳ liền trước" — với month/quarter/year phải là đơn vị lịch liền trước
     * (tháng/quý/năm trước đó), không phải "cùng số ngày liền trước" (vì độ dài
     * quý/tháng không đều nhau nên sẽ lệch khỏi ranh giới lịch). Chỉ custom mới
     * dùng cách tính theo số ngày vì không có đơn vị lịch để bám vào.
     */
    private function previousCalendarPeriod(string $periodType, Carbon $from, Carbon $to, int $year, int $month, int $quarter): array
    {
        switch ($periodType) {
            case 'month':
                $prevMonth = $month - 1;
                $prevYear  = $year;
                if ($prevMonth < 1) {
                    $prevMonth = 12;
                    $prevYear--;
                }
                $pFrom = Carbon::create($prevYear, $prevMonth, 1)->startOfMonth();
                $pTo   = $pFrom->copy()->endOfMonth();
                break;

            case 'quarter':
                $prevQuarter = $quarter - 1;
                $prevYear    = $year;
                if ($prevQuarter < 1) {
                    $prevQuarter = 4;
                    $prevYear--;
                }
                $startMonth = ($prevQuarter - 1) * 3 + 1;
                $pFrom      = Carbon::create($prevYear, $startMonth, 1)->startOfMonth();
                $pTo        = $pFrom->copy()->addMonths(2)->endOfMonth();
                break;

            case 'year':
                $pFrom = Carbon::create($year - 1, 1, 1)->startOfDay();
                $pTo   = Carbon::create($year - 1, 12, 31)->endOfDay();
                break;

            default: // custom
                // diffInDays giữa startOfDay và endOfDay luôn ra số thập phân (thiếu vài micro-giây
                // so với mốc 24h) — phải quy cả 2 mốc về startOfDay trước khi diff để tránh subDays()
                // bị truyền float và lùi thiếu 1 ngày.
                $days  = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
                $pTo   = $from->copy()->subDay()->endOfDay();
                $pFrom = $pTo->copy()->subDays($days - 1)->startOfDay();
                break;
        }

        return [
            'date_from' => $pFrom->toDateString(),
            'date_to'   => $pTo->toDateString(),
            'label'     => 'Kỳ liền trước',
        ];
    }

    private function fileSlug(array $period): string
    {
        return match ($period['type']) {
            'month'   => sprintf('thang-%02d-%s', (int) substr($period['date_from'], 5, 2), substr($period['date_from'], 0, 4)),
            'quarter' => sprintf('quy-%d-%s', (int) ceil(((int) substr($period['date_from'], 5, 2)) / 3), substr($period['date_from'], 0, 4)),
            'custom'  => 'tu-' . str_replace('-', '', $period['date_from']) . '-den-' . str_replace('-', '', $period['date_to']),
            default   => 'nam-' . substr($period['date_to'], 0, 4),
        };
    }

    private function availableYears(): array
    {
        $current = now()->year;
        return range($current - 3, $current + 1);
    }
}
