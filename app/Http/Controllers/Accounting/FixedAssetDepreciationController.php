<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use App\Services\FixedAssetDepreciationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetDepreciationController extends Controller
{
    public function __construct(protected FixedAssetDepreciationService $service) {}

    /**
     * Trang xem trước + chạy khấu hao tháng.
     */
    public function runPage(): Response
    {
        $currentPeriod = now()->format('Y-m');

        return Inertia::render('Accounting/FixedAssets/Depreciation/Run', [
            'defaultPeriod' => $currentPeriod,
        ]);
    }

    /**
     * AJAX: xem trước khấu hao không ghi DB.
     */
    public function preview(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => 'required|regex:/^\d{4}-\d{2}$/'])['period'];

        $rows = $this->service->previewPeriod($period);

        return response()->json([
            'period' => $period,
            'rows'   => $rows,
            'total'  => array_sum(array_column($rows, 'amount')),
            'count'  => count($rows),
        ]);
    }

    /**
     * Chạy khấu hao thực sự: tạo records + bút toán draft.
     */
    public function run(Request $request): RedirectResponse
    {
        $period = $request->validate(['period' => 'required|regex:/^\d{4}-\d{2}$/'])['period'];

        try {
            $result = $this->service->runPeriod($period, createJournal: true, isDraft: true);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = "Kỳ {$period}: {$result['processed']} tài sản đã tính khấu hao, {$result['skipped']} bỏ qua.";
        if (! empty($result['errors'])) {
            $msg .= ' Lỗi: ' . implode('; ', array_slice($result['errors'], 0, 3));
            return back()->with('error', $msg);
        }

        return back()->with('success', $msg);
    }

    /**
     * Post bút toán draft → posted.
     */
    public function postJournal(Request $request): RedirectResponse
    {
        $jeId = $request->validate(['journal_entry_id' => 'required|exists:journal_entries,id'])['journal_entry_id'];

        $je = JournalEntry::findOrFail($jeId);

        try {
            $this->service->postDepreciationJournal($je);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã ghi sổ bút toán khấu hao.');
    }

    /**
     * Hủy một record khấu hao (kỳ chưa khóa).
     */
    public function reverse(FixedAssetDepreciation $depreciation): RedirectResponse
    {
        try {
            $this->service->reverseDepreciation($depreciation);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Đã hủy khấu hao tháng ' . $depreciation->period . '.');
    }
}
