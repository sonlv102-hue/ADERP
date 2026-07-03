<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Services\SmallToolAllocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolAllocationController extends Controller
{
    public function __construct(protected SmallToolAllocationService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('ccdc.allocate');

        $period  = $request->period ?? now()->format('Y-m');
        $preview = $this->service->previewPeriod($period);

        return Inertia::render('Accounting/SmallTools/Allocations/Index', [
            'period'        => $period,
            'preview'       => $preview,
            'totalAmount'   => array_sum(array_column($preview, 'amount')),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.allocate');

        $data = $request->validate(['period' => 'required|string|size:7']);

        try {
            $result = $this->service->runPeriod($data['period']);
            $count  = count($result['processed'] ?? []);
            return back()->with('success', "Đã phân bổ {$count} CCDC kỳ {$data['period']}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function reverse(SmallToolAllocation $allocation): RedirectResponse
    {
        $this->authorize('ccdc.allocate');

        try {
            $this->service->reverseAllocation($allocation);
            return back()->with('success', "Đã đảo phân bổ kỳ {$allocation->period}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function pause(SmallTool $tool, Request $request): RedirectResponse
    {
        $this->authorize('ccdc.allocate');

        $data = $request->validate(['reason' => 'required|string|max:500']);

        try {
            $this->service->pause($tool, $data['reason']);
            return back()->with('success', "Đã tạm dừng phân bổ CCDC {$tool->code}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function resume(SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.allocate');

        try {
            $result = $this->service->resume($tool);
            $msg = $result['next_period']
                ? "Đã tiếp tục phân bổ CCDC {$tool->code}, kỳ tới: {$result['next_period']}."
                : "Đã tiếp tục phân bổ CCDC {$tool->code}.";
            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
