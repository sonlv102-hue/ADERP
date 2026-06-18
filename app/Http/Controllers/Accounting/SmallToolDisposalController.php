<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\SmallTool;
use App\Models\SmallToolDisposal;
use App\Services\SmallToolService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolDisposalController extends Controller
{
    public function __construct(protected SmallToolService $service) {}

    public function create(SmallTool $tool): Response
    {
        $this->authorize('ccdc.dispose');

        return Inertia::render('Accounting/SmallTools/Disposals/Form', [
            'tool' => [
                'id'             => $tool->id,
                'code'           => $tool->code,
                'name'           => $tool->name,
                'status'         => $tool->status->value,
                'original_cost'  => (float) $tool->original_cost,
                'total_allocated' => (float) $tool->total_allocated,
                'total_remaining' => $tool->total_remaining,
            ],
        ]);
    }

    public function store(Request $request, SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.dispose');

        $data = $request->validate([
            'disposal_type'          => 'required|in:broken,lost,liquidated',
            'disposal_date'          => 'required|date',
            'reason'                 => 'required|string',
            'expense_account_code'   => 'required|string|max:20',
            'recovery_amount'        => 'nullable|numeric|min:0',
            'recovery_account_code'  => 'nullable|string|max:20',
            'recovery_vat_amount'    => 'nullable|numeric|min:0',
            'disposal_cost'          => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string',
        ]);

        $disposal = SmallToolDisposal::create([
            ...$data,
            'code'               => SmallToolDisposal::generateCode(),
            'small_tool_id'      => $tool->id,
            'net_value_snapshot' => $tool->total_remaining,
            'status'             => 'draft',
            'created_by'         => auth()->id(),
        ]);

        try {
            $this->service->approveDisposal($disposal->fresh('tool'));
            return redirect()->route('accounting.small-tools.show', $tool)
                ->with('success', 'Đã ghi nhận xử lý CCDC và tạo bút toán.');
        } catch (\Throwable $e) {
            $disposal->delete();
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
