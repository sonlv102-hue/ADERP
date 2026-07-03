<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\SmallToolStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use App\Services\SmallToolAllocationService;
use App\Services\SmallToolJournalService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SmallToolOpeningBalanceController extends Controller
{
    public function __construct(
        protected SmallToolAllocationService $allocationService,
        protected SmallToolJournalService $journal,
    ) {}

    public function create(): Response
    {
        $this->authorize('ccdc.manage');

        return Inertia::render('Accounting/SmallTools/OpeningBalance/Form', [
            'nextCode'   => SmallTool::generateCode(),
            'categories' => SmallToolCategory::orderBy('name')->get(['id', 'name']),
            'employees'  => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'category_id'             => 'nullable|exists:small_tool_categories,id',
            'unit'                    => 'nullable|string|max:30',
            'quantity'                => 'required|integer|min:1',
            'original_cost'           => 'required|numeric|min:0',
            'allocation_periods'      => 'required|integer|min:1',
            'periods_elapsed'         => 'required|integer|min:0|lte:allocation_periods',
            'remaining_amount'        => 'required|numeric|min:0',
            'opening_balance_period'  => 'required|regex:/^\d{4}-\d{2}$/',
            'department'              => 'nullable|string|max:100',
            'responsible_employee_id' => 'nullable|exists:employees,id',
            'pending_account_code'    => 'nullable|string|max:20',
            'expense_account_code'    => 'nullable|string|max:20',
            'notes'                   => 'nullable|string',
        ]);

        $totalAllocated = round((float) $data['original_cost'] - (float) $data['remaining_amount'], 2);
        if ($totalAllocated < 0) {
            return back()->withErrors(['remaining_amount' => 'Giá trị còn lại không được lớn hơn nguyên giá.'])->withInput();
        }

        // Kỳ chuyển đổi = kỳ ĐẦU TIÊN sẽ được phân bổ tiếp trong hệ thống mới (index = periods_elapsed).
        // buildSchedule() tính periodDate = allocation_start_date + i tháng (i từ periods_allocated),
        // nên phải lùi allocation_start_date lại đúng periods_elapsed tháng để kỳ i=periods_elapsed rơi vào opening_balance_period.
        $scheduleStartDate = Carbon::parse($data['opening_balance_period'] . '-01')->subMonths((int) $data['periods_elapsed']);

        $tool = DB::transaction(function () use ($data, $totalAllocated, $scheduleStartDate) {
            $tool = SmallTool::create([
                'code'                    => SmallTool::generateCode(),
                'name'                    => $data['name'],
                'category_id'             => $data['category_id'] ?? null,
                'unit'                    => $data['unit'] ?? 'cái',
                'quantity'                => $data['quantity'],
                'original_cost'           => $data['original_cost'],
                'vat_amount'              => 0,
                'total_cost'              => $data['original_cost'],
                'acquisition_type'        => 'direct',
                'recognition_method'      => 'allocation',
                'allocation_periods'      => $data['allocation_periods'],
                'allocation_start_date'   => $scheduleStartDate,
                'department'              => $data['department'] ?? null,
                'responsible_employee_id' => $data['responsible_employee_id'] ?? null,
                'pending_account_code'    => $data['pending_account_code'] ?? '2422',
                'expense_account_code'    => $data['expense_account_code'] ?? '6422',
                'periods_allocated'       => $data['periods_elapsed'],
                'total_allocated'         => $totalAllocated,
                'status'                  => SmallToolStatus::Allocating,
                'allocation_status'       => 'active',
                'is_opening_balance'      => true,
                'opening_balance_period'  => $data['opening_balance_period'],
                'opening_balance_note'    => $data['notes'] ?? null,
                'notes'                   => $data['notes'] ?? null,
                'created_by'              => auth()->id(),
            ]);

            $this->allocationService->buildSchedule($tool);

            $je = $this->journal->createOpeningBalanceJournal($tool);
            $tool->update(['acquisition_journal_entry_id' => $je->id]);

            return $tool;
        });

        return redirect()->route('accounting.small-tools.show', $tool)
            ->with('success', "Đã nhập số dư đầu kỳ CCDC {$tool->code}.");
    }

    public function destroy(SmallTool $tool): RedirectResponse
    {
        $this->authorize('ccdc.manage');

        if (! $tool->is_opening_balance) {
            return back()->with('error', 'Đây không phải bản ghi số dư đầu kỳ.');
        }

        if ($tool->allocations()->where('status', 'posted')->exists()) {
            return back()->with('error', 'Đã có kỳ phân bổ được ghi sổ — hãy đảo (reverse) các kỳ đó trước khi xóa.');
        }

        $tool->loadMissing('acquisitionJournalEntry');
        if ($tool->acquisitionJournalEntry && $tool->acquisitionJournalEntry->status === 'posted') {
            return back()->with('error', 'Bút toán số dư đầu kỳ đã ghi sổ — hãy hủy (void) bút toán đó trước khi xóa.');
        }

        DB::transaction(function () use ($tool) {
            $tool->allocations()->delete();
            $tool->delete();
        });

        return redirect()->route('accounting.small-tools.index')->with('success', 'Đã xóa số dư đầu kỳ CCDC.');
    }
}
