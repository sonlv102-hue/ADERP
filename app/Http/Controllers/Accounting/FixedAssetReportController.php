<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetReportController extends Controller
{
    // Sổ TSCĐ
    public function ledger(Request $request): Response
    {
        $query = FixedAsset::with('category')
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->department, fn ($q) => $q->where('department', 'ilike', '%' . $request->department . '%'))
            ->orderBy('code');

        $assets = $query->get()->map(fn ($fa) => [
            'id'                       => $fa->id,
            'code'                     => $fa->code,
            'name'                     => $fa->name,
            'category_name'            => $fa->category?->name ?? $fa->category,
            'department'               => $fa->department,
            'acquisition_date'         => $fa->acquisition_date?->format('d/m/Y'),
            'placed_in_service_date'   => $fa->placed_in_service_date?->format('d/m/Y'),
            'acquisition_cost'         => (float) $fa->acquisition_cost,
            'accumulated_depreciation' => (float) $fa->accumulated_depreciation,
            'net_book_value'           => $fa->net_book_value,
            'useful_life_months'       => $fa->useful_life_months,
            'depreciation_end_date'    => $fa->depreciation_end_date?->format('d/m/Y'),
            'status_label'             => $fa->status->label(),
            'original_cost_account_code'   => $fa->original_cost_account_code,
            'accumulated_dep_account_code' => $fa->accumulated_dep_account_code,
        ]);

        return Inertia::render('Accounting/FixedAssets/Reports/Ledger', [
            'assets'     => $assets,
            'categories' => FixedAssetCategory::orderBy('name')->get(['id', 'name']),
            'filters'    => $request->only(['category_id', 'status', 'department']),
            'totals'     => [
                'original_cost'         => $assets->sum('acquisition_cost'),
                'accumulated_dep'       => $assets->sum('accumulated_depreciation'),
                'net_book_value'        => $assets->sum('net_book_value'),
            ],
        ]);
    }

    // Bảng tính và phân bổ khấu hao theo tháng
    public function schedule(Request $request): Response
    {
        $period = $request->input('period', now()->format('Y-m'));

        $deps = FixedAssetDepreciation::with(['fixedAsset.category'])
            ->where('period', $period)
            ->orderBy('fixed_asset_id')
            ->get();

        $rows = $deps->map(fn ($d) => [
            'asset_code'      => $d->fixedAsset?->code,
            'asset_name'      => $d->fixedAsset?->name,
            'category_name'   => $d->fixedAsset?->category?->name,
            'department'      => $d->fixedAsset?->department,
            'expense_account' => $d->fixedAsset?->depreciation_expense_account_code,
            'dep_account'     => $d->fixedAsset?->accumulated_dep_account_code,
            'amount'          => (float) $d->amount,
            'accumulated_after' => (float) $d->accumulated_before + (float) $d->amount,
            'net_book_value'  => (float) $d->net_book_value_after,
            'status'          => $d->status,
            'journal_entry_id' => $d->journal_entry_id,
        ]);

        return Inertia::render('Accounting/FixedAssets/Reports/Schedule', [
            'period'  => $period,
            'rows'    => $rows,
            'total'   => $rows->sum('amount'),
        ]);
    }

    // Báo cáo tăng giảm TSCĐ
    public function movement(Request $request): Response
    {
        $year  = $request->input('year', now()->year);
        $start = "{$year}-01-01";
        $end   = "{$year}-12-31";

        $opening  = FixedAsset::withTrashed()->where('acquisition_date', '<', $start)->get();
        $increase = FixedAsset::withTrashed()->whereBetween('recognition_date', [$start, $end])->get();
        $decrease = FixedAsset::withTrashed()->whereNotNull('deleted_at')
            ->whereBetween('deleted_at', [$start, $end])->get();

        return Inertia::render('Accounting/FixedAssets/Reports/Movement', [
            'year'     => $year,
            'opening'  => $this->assetSummary($opening),
            'increase' => $this->assetSummary($increase),
            'decrease' => $this->assetSummary($decrease),
            'closing'  => [
                'count' => $opening->count() + $increase->count() - $decrease->count(),
                'cost'  => $opening->sum('acquisition_cost') + $increase->sum('acquisition_cost') - $decrease->sum('acquisition_cost'),
            ],
        ]);
    }

    // Báo cáo đối chiếu kế toán TK 211 / 214
    public function reconciliation(Request $request): Response
    {
        $period = $request->input('period', now()->format('Y-m'));

        // Tổng nguyên giá từ danh mục TSCĐ
        $catalogOriginalCost = FixedAsset::whereNull('deleted_at')->sum('acquisition_cost');
        $catalogAccumDep     = FixedAsset::whereNull('deleted_at')->sum('accumulated_depreciation');

        // Số dư TK 211 từ journal_entry_lines
        $tk211Balance = JournalEntryLine::join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.account_code', 'like', '211%')
            ->selectRaw('SUM(debit) - SUM(credit) as balance')
            ->value('balance') ?? 0;

        // Số dư TK 214
        $tk214Balance = JournalEntryLine::join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.account_code', 'like', '214%')
            ->selectRaw('SUM(credit) - SUM(debit) as balance')
            ->value('balance') ?? 0;

        // Bút toán 211/214 không gắn fixed_asset_id
        $unlinkedLines = JournalEntryLine::join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->whereIn(DB::raw("SUBSTRING(journal_entry_lines.account_code, 1, 3)"), ['211', '214'])
            ->whereNull('journal_entry_lines.fixed_asset_id')
            ->count();

        return Inertia::render('Accounting/FixedAssets/Reports/Reconciliation', [
            'period'                => $period,
            'catalog_original_cost' => (float) $catalogOriginalCost,
            'catalog_accum_dep'     => (float) $catalogAccumDep,
            'tk211_balance'         => (float) $tk211Balance,
            'tk214_balance'         => (float) $tk214Balance,
            'diff_211'              => (float) $catalogOriginalCost - (float) $tk211Balance,
            'diff_214'              => (float) $catalogAccumDep - (float) $tk214Balance,
            'unlinked_je_lines'     => $unlinked_lines ?? 0,
        ]);
    }

    // Báo cáo kiểm tra tuân thủ
    public function compliance(): Response
    {
        $warnings = [];

        // TSCĐ dưới 30 triệu
        $under30m = FixedAsset::whereNull('deleted_at')->where('acquisition_cost', '<', 30000000)->count();
        if ($under30m > 0) {
            $warnings[] = ['type' => 'warning', 'message' => "{$under30m} TSCĐ có nguyên giá dưới 30 triệu VND."];
        }

        // Chưa có ngày đưa vào sử dụng nhưng đang active
        $noServiceDate = FixedAsset::whereNull('deleted_at')->where('status', 'active')->whereNull('placed_in_service_date')->count();
        if ($noServiceDate > 0) {
            $warnings[] = ['type' => 'error', 'message' => "{$noServiceDate} TSCĐ đang active nhưng chưa có ngày đưa vào sử dụng."];
        }

        // Chưa có tài khoản chi phí khấu hao
        $noExpenseAcc = FixedAsset::whereNull('deleted_at')->whereNull('depreciation_expense_account_code')->count();
        if ($noExpenseAcc > 0) {
            $warnings[] = ['type' => 'warning', 'message' => "{$noExpenseAcc} TSCĐ chưa chọn tài khoản chi phí khấu hao."];
        }

        // Có lịch khấu hao planned nhưng chưa post
        $unpostedDep = FixedAssetDepreciation::where('status', 'planned')->count();
        if ($unpostedDep > 0) {
            $warnings[] = ['type' => 'warning', 'message' => "{$unpostedDep} kỳ khấu hao đã tính nhưng bút toán chưa ghi sổ."];
        }

        // TSCĐ đã hết khấu hao nhưng vẫn active (NBV = 0 nhưng status = active)
        $overdep = FixedAsset::whereNull('deleted_at')->where('status', 'active')
            ->whereColumn('accumulated_depreciation', '>=', 'depreciable_amount')
            ->where('depreciable_amount', '>', 0)
            ->count();
        if ($overdep > 0) {
            $warnings[] = ['type' => 'error', 'message' => "{$overdep} TSCĐ đã khấu hao hết nhưng vẫn ở trạng thái đang sử dụng."];
        }

        // Bút toán vào TK cha 211/214
        $parentJe = JournalEntryLine::whereIn('account_code', ['211', '214'])
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.status', 'posted')
            ->count();
        if ($parentJe > 0) {
            $warnings[] = ['type' => 'error', 'message' => "{$parentJe} bút toán đã ghi sổ vào TK tổng hợp 211 hoặc 214."];
        }

        return Inertia::render('Accounting/FixedAssets/Reports/Compliance', [
            'warnings' => $warnings,
        ]);
    }

    private function assetSummary($assets): array
    {
        return [
            'count' => $assets->count(),
            'cost'  => (float) $assets->sum('acquisition_cost'),
        ];
    }
}
