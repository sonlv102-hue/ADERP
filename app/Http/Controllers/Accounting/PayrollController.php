<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\PayrollExport;
use App\Http\Controllers\Controller;
use App\Models\Fund;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Services\PayrollRollbackService;
use App\Services\PayrollService;
use App\Services\PitCalculatorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService        $service,
        private PitCalculatorService  $pit,
        private PayrollRollbackService $rollbackService,
    ) {}

    public function index(Request $request): Response
    {
        $query = Payroll::with('creator')->orderByDesc('period');

        if ($request->filled('period')) {
            $query->where('period', 'like', $request->input('period') . '%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $payrolls = $query->paginate(20)->withQueryString()->through(fn (Payroll $p) => [
            'id'                => $p->id,
            'code'              => $p->code,
            'period'            => $p->period,
            'status'            => $p->status->value,
            'status_label'      => $p->status->label(),
            'status_color'      => $p->status->color(),
            'total_base_salary' => (float) $p->total_base_salary,
            'total_allowance'   => (float) $p->total_allowance,
            'total_bonus'       => (float) $p->total_bonus,
            'total_deductions'  => (float) $p->total_deductions,
            'total_net_salary'  => (float) $p->total_net_salary,
            'creator'           => $p->creator?->name,
            'created_at'        => $p->created_at->format('d/m/Y'),
        ]);

        return Inertia::render('Accounting/Payrolls/Index', [
            'payrolls' => $payrolls,
            'filters'  => $request->only('period', 'status'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Payrolls/Form', [
            'payroll' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'notes'  => 'nullable|string|max:500',
        ]);

        try {
            $this->service->createPayroll($data['period'], $data['notes'] ?? null);
            return redirect()->route('accounting.payrolls.index')
                ->with('success', 'Bảng lương tháng đã được lập thành công.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function show(Payroll $payroll): Response
    {
        $payroll->load('creator', 'locker', 'unionFeeConfirmedBy');

        $items = $payroll->items()->with('employee', 'user', 'salaryJournalEntry', 'adjustedBy')
            ->orderBy('id')
            ->get()
            ->map(fn (PayrollItem $item) => $this->itemDTO($item));

        $canManage = auth()->user()->can('accounting.manage');

        $funds = Fund::where('is_active', true)->orderBy('type')->orderBy('name')
            ->get(['id', 'name', 'type', 'account_code']);

        return Inertia::render('Accounting/Payrolls/Show', [
            'can_manage' => $canManage,
            'payroll' => [
                'id'                        => $payroll->id,
                'code'                      => $payroll->code,
                'period'                    => $payroll->period,
                'status'                    => $payroll->status->value,
                'status_label'              => $payroll->status->label(),
                'status_color'              => $payroll->status->color(),
                'total_base_salary'         => (float) $payroll->total_base_salary,
                'total_allowance'           => (float) $payroll->total_allowance,
                'total_bonus'               => (float) $payroll->total_bonus,
                'total_gross'               => (float) $payroll->total_gross,
                'total_insurance_employee'  => (float) $payroll->total_insurance_employee,
                'total_insurance_employer'  => (float) $payroll->total_insurance_employer,
                'total_pit'                 => (float) $payroll->total_pit,
                'total_deductions'          => (float) $payroll->total_deductions,
                'total_net_salary'          => (float) $payroll->total_net_salary,
                'total_adjustment'          => (float) ($payroll->total_adjustment ?? 0),
                'creator'                   => $payroll->creator?->name,
                'notes'                     => $payroll->notes,
                'created_at'                => $payroll->created_at->format('d/m/Y H:i'),
                'is_locked'                 => (bool) $payroll->is_locked,
                'locked_by_name'            => $payroll->locker?->name,
                'locked_at'                 => $payroll->locked_at?->format('d/m/Y H:i'),
                'total_trade_union_fee'     => (float) $payroll->total_trade_union_fee,
                'union_fee_include'         => $payroll->union_fee_include,
                'union_fee_confirmed_by'    => $payroll->unionFeeConfirmedBy?->name,
                'union_fee_confirmed_at'    => $payroll->union_fee_confirmed_at?->format('d/m/Y H:i'),
            ],
            'items' => $items,
            'funds' => $funds,
        ]);
    }

    public function updateItem(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa, không thể chỉnh sửa.');
        }

        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể chỉnh sửa lương khi bảng lương ở trạng thái nháp.');
        }

        if ($item->payroll_id !== $payroll->id) {
            return back()->with('error', 'Dòng lương không thuộc bảng lương này.');
        }

        $data = $request->validate([
            'base_salary'              => 'required|numeric|min:0',
            'allowance_responsibility' => 'nullable|numeric|min:0',
            'allowance_lunch'          => 'nullable|numeric|min:0',
            'allowance_phone'          => 'nullable|numeric|min:0',
            'allowance_transport'      => 'nullable|numeric|min:0',
            'allowance_performance'    => 'nullable|numeric|min:0',
            'allowance'                => 'nullable|numeric|min:0',
            'bonus'                    => 'nullable|numeric|min:0',
            'standard_days'            => 'nullable|integer|min:1|max:31',
            'working_days'             => 'nullable|integer|min:0|max:31',
            'advance'                  => 'nullable|numeric|min:0',
            'insurance_subject'        => 'nullable|boolean',
            'dependents_count'         => 'nullable|integer|min:0|max:10',
            // BHXH override — kế toán có thể sửa thủ công từng dòng
            'bhxh_employer'            => 'nullable|numeric|min:0',
            'bhyt_employer'            => 'nullable|numeric|min:0',
            'bhtn_employer'            => 'nullable|numeric|min:0',
            'bhxh_employee'            => 'nullable|numeric|min:0',
            'bhyt_employee'            => 'nullable|numeric|min:0',
            'bhtn_employee'            => 'nullable|numeric|min:0',
            // PIT override — chỉ admin
            'pit_override_enabled'     => 'nullable|boolean',
            'pit_override'             => 'nullable|numeric|min:0',
        ]);

        $base           = (float) $data['base_salary'];
        $allocResp      = (float) ($data['allowance_responsibility'] ?? $item->allowance_responsibility ?? 0);
        $allocLunch     = (float) ($data['allowance_lunch']          ?? $item->allowance_lunch          ?? 0);
        $allocPhone     = (float) ($data['allowance_phone']          ?? $item->allowance_phone          ?? 0);
        $allocTransport = (float) ($data['allowance_transport']      ?? $item->allowance_transport      ?? 0);
        $allocPerf      = (float) ($data['allowance_performance']    ?? $item->allowance_performance    ?? 0);
        $allocOther     = (float) ($data['allowance']                ?? $item->allowance                ?? 0);
        $bonus          = (float) ($data['bonus']                    ?? 0);
        $totalAllw      = $allocResp + $allocLunch + $allocPhone + $allocTransport + $allocPerf + $allocOther;
        $dependents     = (int)   ($data['dependents_count']         ?? $item->dependents_count         ?? 0);
        $insSubject     = (bool)  ($data['insurance_subject']        ?? $item->insurance_subject        ?? true);
        $workingDays    = (int)   ($data['working_days']  ?? $item->working_days  ?? $item->standard_days ?? 26);
        $standardDays   = (int)   ($data['standard_days'] ?? $item->standard_days ?? 26);

        // Phân loại theo Nghị định 158/2025:
        //   BHXH-subject  : allocResp + allocOther (phụ cấp lương ổn định trong HĐLĐ)
        //   Non-BHXH      : ăn trưa + xăng xe + ĐT + hiệu quả CV + bonus (phúc lợi/biến động)
        $bhxhAllowances    = $allocResp + $allocOther;
        $nonBhxhAllowances = $allocLunch + $allocPhone + $allocTransport + $allocPerf + $bonus;

        $bd = $this->pit->breakdown($base, $bhxhAllowances, $nonBhxhAllowances, $dependents, $insSubject, $workingDays, $standardDays);

        // Dùng override nếu kế toán sửa thủ công, ngược lại dùng giá trị công thức
        $bhxhEmpl   = isset($data['bhxh_employer']) ? (float) $data['bhxh_employer'] : $bd['bhxh_employer'];
        $bhytEmpl   = isset($data['bhyt_employer']) ? (float) $data['bhyt_employer'] : $bd['bhyt_employer'];
        $bhtnEmpl   = isset($data['bhtn_employer']) ? (float) $data['bhtn_employer'] : $bd['bhtn_employer'];
        $bhxhEmp    = isset($data['bhxh_employee']) ? (float) $data['bhxh_employee'] : $bd['bhxh_employee'];
        $bhytEmp    = isset($data['bhyt_employee']) ? (float) $data['bhyt_employee'] : $bd['bhyt_employee'];
        $bhtnEmp    = isset($data['bhtn_employee']) ? (float) $data['bhtn_employee'] : $bd['bhtn_employee'];
        $insEmpTotal = $bhxhEmp + $bhytEmp + $bhtnEmp;

        // Tính lại PIT nếu ins_employee thay đổi so với công thức
        $pitCalc = $this->pit->calcPitFromGross($bd['gross_salary'], $insEmpTotal, $dependents);

        // PIT override — chỉ admin có thể ghi đè
        $pitOverrideEnabled = !empty($data['pit_override_enabled']) && auth()->user()->hasRole('admin');
        $pitFinal = $pitOverrideEnabled && isset($data['pit_override'])
            ? (float) $data['pit_override']
            : $pitCalc['pit'];
        $netFinal = (int) round($bd['gross_salary'] - $insEmpTotal - $pitFinal);

        $item->update([
            'base_salary'              => $base,
            'allowance'                => $allocOther,
            'allowance_responsibility' => $allocResp,
            'allowance_lunch'          => $allocLunch,
            'allowance_phone'          => $allocPhone,
            'allowance_transport'      => $allocTransport,
            'allowance_performance'    => $allocPerf,
            'bonus'                    => $bonus,
            'gross_salary'             => $bd['gross_salary'],
            'insurance_base'           => $bd['insurance_base'],
            'bhxh_employee'            => $bhxhEmp,
            'bhyt_employee'            => $bhytEmp,
            'bhtn_employee'            => $bhtnEmp,
            'bhxh_employer'            => $bhxhEmpl,
            'bhyt_employer'            => $bhytEmpl,
            'bhtn_employer'            => $bhtnEmpl,
            'pit'                      => $pitFinal,
            'dependents_count'         => $dependents,
            'deductions'               => $insEmpTotal + $pitFinal,
            'net_salary'               => $netFinal,
            'standard_days'            => $standardDays,
            'working_days'             => $workingDays,
            'advance'                  => (float) ($data['advance'] ?? $item->advance ?? 0),
            'insurance_subject'        => $insSubject,
        ]);

        $this->service->recalculateTotals($payroll);

        return back()->with('success', 'Đã cập nhật lương của nhân viên.');
    }

    private function itemDTO(PayrollItem $item): array
    {
        $item->loadMissing(['salaryJournalEntry', 'payroll']);
        $gross       = (float) $item->gross_salary;
        $insEmp      = (float) $item->bhxh_employee + (float) $item->bhyt_employee + (float) $item->bhtn_employee;

        // Lấy cấu hình PIT theo tháng lương — không dùng hằng số cứng
        try {
            $payrollDate = \Carbon\Carbon::parse($item->payroll?->month ?? now()->format('Y-m').'-01');
            $pitCfg      = \App\Models\PitConfig::forDate($payrollDate);
            $personalDed = $pitCfg->personal_deduction
                + ((int) $item->dependents_count * $pitCfg->dependent_deduction);
        } catch (\Throwable) {
            $personalDed = \App\Services\PitCalculatorService::PERSONAL_DEDUCTION
                + ((int) $item->dependents_count * \App\Services\PitCalculatorService::DEPENDENT_DEDUCTION);
        }

        $emp = $item->employee;
        return [
            'id'                       => $item->id,
            'employee_name'            => $emp?->name ?? $item->user?->name,
            'employee_code'            => $emp?->code,
            'department'               => $emp?->department,
            'position'                 => $emp?->position ?? $item->user?->roles?->first()?->name ?? 'Nhân viên',
            'employment_type'          => $emp?->employment_type?->label() ?? '',
            'pit_tax_code'             => $emp?->pit_tax_code ?? $item->user?->pit_tax_code,
            'base_salary'              => (float) $item->base_salary,
            'allowance'                => (float) $item->allowance,
            'allowance_responsibility' => (float) ($item->allowance_responsibility ?? 0),
            'allowance_lunch'          => (float) ($item->allowance_lunch          ?? 0),
            'allowance_phone'          => (float) ($item->allowance_phone          ?? 0),
            'allowance_transport'      => (float) ($item->allowance_transport      ?? 0),
            'allowance_performance'    => (float) ($item->allowance_performance    ?? 0),
            'bonus'                    => (float) $item->bonus,
            'gross_salary'             => $gross,
            'insurance_base'           => (float) $item->insurance_base,
            'bhxh_employee'            => (float) $item->bhxh_employee,
            'bhyt_employee'            => (float) $item->bhyt_employee,
            'bhtn_employee'            => (float) $item->bhtn_employee,
            'bhxh_employer'            => (float) $item->bhxh_employer,
            'bhyt_employer'            => (float) $item->bhyt_employer,
            'bhtn_employer'            => (float) $item->bhtn_employer,
            'pit'                      => (float) $item->pit,
            'dependents_count'         => (int)   $item->dependents_count,
            'personal_deduction'       => round($personalDed),
            'taxable_for_pit'          => max(0, round($gross - $insEmp - $personalDed)),
            'deductions'               => (float) $item->deductions,
            'net_salary'               => (float) $item->net_salary,
            'working_days'             => (int)   ($item->working_days       ?? 26),
            'standard_days'            => (int)   ($item->standard_days     ?? 26),
            'actual_working_days'      => (float) ($item->actual_working_days ?? 0),
            'paid_leave_days'          => (float) ($item->paid_leave_days     ?? 0),
            'unpaid_leave_days'        => (float) ($item->unpaid_leave_days   ?? 0),
            'overtime_days'            => (float) ($item->overtime_days       ?? 0),
            'advance'                  => (float) ($item->advance             ?? 0),
            'insurance_subject'        => (bool)  ($item->insurance_subject ?? true),
            'adjustment_amount'        => (float) ($item->adjustment_amount ?? 0),
            'adjustment_reason'        => $item->adjustment_reason,
            'adjustment_taxable'       => (bool)  ($item->adjustment_taxable ?? true),
            'adjusted_by'              => $item->adjustedBy?->name ?? null,
            'adjusted_at'              => $item->adjusted_at?->format('d/m/Y H:i'),
            'thuc_linh'                => max(0, (float) $item->net_salary + (float) ($item->adjustment_amount ?? 0) - (float) ($item->advance ?? 0)),
            'status'                   => $item->status->value,
            'status_label'             => $item->status->label(),
            'status_color'             => $item->status->color(),
            'paid_at'                  => $item->paid_at?->format('d/m/Y H:i'),
            'salary_journal_entry'     => $item->salaryJournalEntry ? [
                'id'   => $item->salaryJournalEntry->id,
                'code' => $item->salaryJournalEntry->code,
            ] : null,
        ];
    }

    public function lock(Payroll $payroll): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã được khóa.');
        }

        $payroll->update([
            'is_locked' => true,
            'locked_by' => auth()->id(),
            'locked_at' => now(),
        ]);

        return back()->with('success', "Bảng lương {$payroll->code} đã được khóa.");
    }

    public function unlock(Payroll $payroll): RedirectResponse
    {
        if (!auth()->user()->hasRole('admin')) {
            return back()->with('error', 'Chỉ Admin mới có thể mở khóa bảng lương.');
        }

        if (!$payroll->is_locked) {
            return back()->with('error', 'Bảng lương chưa bị khóa.');
        }

        $payroll->update([
            'is_locked' => false,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        return back()->with('success', "Đã mở khóa bảng lương {$payroll->code}.");
    }

    public function confirm(Payroll $payroll): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa, không thể xác nhận.');
        }

        try {
            $this->service->confirmPayroll($payroll);
            return back()->with('success', 'Bảng lương tháng đã được xác nhận.');
        } catch (\Throwable $e) {
            \Log::error('confirm payroll failed', [
                'payroll_id' => $payroll->id,
                'code'       => $payroll->code,
                'period'     => $payroll->period,
                'user_id'    => auth()->id(),
                'error'      => $e->getMessage(),
            ]);
            return back()->with('error', $e->getMessage());
        }
    }

    public function unconfirm(Payroll $payroll): RedirectResponse
    {
        if (!auth()->user()->hasRole('admin')) {
            return back()->with('error', 'Chỉ Admin mới có thể hủy xác nhận bảng lương.');
        }

        try {
            $this->service->unconfirmPayroll($payroll);
            return back()->with('success', "Đã hủy xác nhận bảng lương {$payroll->code}. Bảng lương trở về trạng thái nháp.");
        } catch (\Throwable $e) {
            \Log::error('unconfirm payroll failed', ['payroll' => $payroll->id, 'msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->with('error', $e->getMessage());
        }
    }

    public function updateAdjustment(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        if ($item->payroll_id !== $payroll->id) {
            return back()->with('error', 'Dòng lương không thuộc bảng lương này.');
        }

        $data = $request->validate([
            'adjustment_amount'  => 'required|numeric',
            'adjustment_reason'  => 'nullable|string|max:500',
            'adjustment_taxable' => 'boolean',
        ]);

        try {
            $this->service->updateAdjustment(
                $item,
                (float) $data['adjustment_amount'],
                $data['adjustment_reason'] ?? null,
                (bool) ($data['adjustment_taxable'] ?? true),
            );
            return back()->with('success', 'Đã cập nhật số điều chỉnh lương.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function syncFromEmployees(Payroll $payroll): RedirectResponse
    {
        try {
            $this->service->syncFromEmployees($payroll);
            return back()->with('success', 'Đã đồng bộ dữ liệu lương từ hồ sơ nhân viên.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function setUnionFee(Request $request, Payroll $payroll): RedirectResponse
    {
        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể thay đổi cấu hình phí công đoàn khi bảng lương ở trạng thái nháp.');
        }

        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa.');
        }

        $data = $request->validate([
            'union_fee_include' => ['required', 'boolean'],
        ]);

        $include = (bool) $data['union_fee_include'];
        $payroll->update([
            'union_fee_include'      => $include,
            'union_fee_confirmed_by' => auth()->id(),
            'union_fee_confirmed_at' => now(),
        ]);

        $label = $include ? 'ghi nhận' : 'không ghi nhận';
        return back()->with('success', "Đã xác nhận {$label} phí công đoàn vào chi phí.");
    }

    public function payEmployee(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        $data = $request->validate([
            'fund_id' => 'required|exists:funds,id',
        ]);

        try {
            $fund = Fund::findOrFail($data['fund_id']);
            if (! $fund->is_active) {
                throw new RuntimeException("Quỹ '{$fund->name}' không còn hoạt động.");
            }
            $this->service->payEmployeeSalary($item, $fund);
            $employeeName = $item->employee?->name ?? $item->user?->name ?? 'nhân viên';
            return back()->with('success', "Đã thanh toán lương cho {$employeeName}.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function exportExcel(Payroll $payroll): BinaryFileResponse
    {
        $this->authorize('accounting.view');
        $payroll->load('creator');
        $items = $payroll->items()->with('employee', 'user', 'salaryJournalEntry', 'adjustedBy')
            ->orderBy('id')->get()
            ->map(fn (PayrollItem $item) => $this->itemDTO($item));

        $filename = 'Bang-luong-' . str_replace('-', '', $payroll->period) . '.xlsx';
        return Excel::download(new PayrollExport($payroll, $items), $filename);
    }

    public function exportPdf(Payroll $payroll): HttpResponse
    {
        $this->authorize('accounting.view');
        $payroll->load('creator');
        $items = $payroll->items()->with('employee', 'user', 'salaryJournalEntry', 'adjustedBy')
            ->orderBy('id')->get()
            ->map(fn (PayrollItem $item) => $this->itemDTO($item));

        $company = \App\Models\Setting::getGroup('company');
        $pdf = Pdf::loadView('pdf.payroll', [
            'payroll' => $payroll,
            'items'   => $items,
            'company' => $company,
        ])->setPaper('a4', 'landscape');

        $filename = 'Bang-luong-' . str_replace('-', '', $payroll->period) . '.pdf';
        return $pdf->download($filename);
    }

    public function rollbackPreview(Payroll $payroll): \Illuminate\Http\JsonResponse
    {
        $this->authorize('accounting.manage');

        try {
            return response()->json($this->rollbackService->preview($payroll));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function rollback(Request $request, Payroll $payroll): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'scope'  => 'required|in:payment_only,payment_and_accrual',
            'reason' => 'required|string|max:500',
        ]);

        try {
            $this->rollbackService->rollback($payroll, $data['scope'], $data['reason']);

            $msg = $data['scope'] === 'payment_and_accrual'
                ? "Đã hủy thanh toán và bút toán lương {$payroll->code}. Bảng lương trở về nháp."
                : "Đã hủy thanh toán lương {$payroll->code}. Bảng lương trở về đã xác nhận.";

            return back()->with('success', $msg);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Payroll $payroll): RedirectResponse
    {
        if ($payroll->is_locked) {
            return back()->with('error', 'Bảng lương đã bị khóa, không thể xóa.');
        }

        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể xóa bảng lương ở trạng thái nháp.');
        }

        $payroll->delete();

        return redirect()->route('accounting.payrolls.index')
            ->with('success', 'Bảng lương tháng đã được xóa.');
    }
}
