<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\Fund;
use App\Services\PayrollService;
use App\Services\PitCalculatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService       $service,
        private PitCalculatorService $pit,
    ) {}

    public function index(Request $request): Response
    {
        $query = Payroll::with('creator')->orderByDesc('period');

        $payrolls = $query->paginate(20)->through(fn (Payroll $p) => [
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
        $payroll->load('creator');

        $items = $payroll->items()->with('user', 'cashVoucher')->get()->map(fn (PayrollItem $item) => [
            'id'              => $item->id,
            'employee_name'   => $item->user?->name,
            'pit_tax_code'    => $item->user?->pit_tax_code,
            'role_label'      => $item->user?->roles?->first()?->name ?? 'Nhân viên',
            'base_salary'     => (float) $item->base_salary,
            'allowance'       => (float) $item->allowance,
            'bonus'           => (float) $item->bonus,
            'gross_salary'    => (float) $item->gross_salary,
            'insurance_base'  => (float) $item->insurance_base,
            'bhxh_employee'   => (float) $item->bhxh_employee,
            'bhyt_employee'   => (float) $item->bhyt_employee,
            'bhtn_employee'   => (float) $item->bhtn_employee,
            'bhxh_employer'   => (float) $item->bhxh_employer,
            'bhyt_employer'   => (float) $item->bhyt_employer,
            'bhtn_employer'   => (float) $item->bhtn_employer,
            'pit'             => (float) $item->pit,
            'dependents_count'=> (int)   $item->dependents_count,
            'deductions'      => (float) $item->deductions,
            'net_salary'      => (float) $item->net_salary,
            'status'          => $item->status->value,
            'status_label'    => $item->status->label(),
            'status_color'    => $item->status->color(),
            'paid_at'         => $item->paid_at?->format('d/m/Y H:i'),
            'cash_voucher'    => $item->cashVoucher ? [
                'id'   => $item->cashVoucher->id,
                'code' => $item->cashVoucher->code,
            ] : null,
        ]);

        $funds = Fund::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']);

        return Inertia::render('Accounting/Payrolls/Show', [
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
                'creator'                   => $payroll->creator?->name,
                'notes'                     => $payroll->notes,
                'created_at'                => $payroll->created_at->format('d/m/Y H:i'),
            ],
            'items' => $items,
            'funds' => $funds,
        ]);
    }

    public function updateItem(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể chỉnh sửa lương khi bảng lương ở trạng thái nháp.');
        }

        if ($item->payroll_id !== $payroll->id) {
            return back()->with('error', 'Dòng lương không thuộc bảng lương này.');
        }

        $data = $request->validate([
            'base_salary'      => 'required|numeric|min:0',
            'allowance'        => 'required|numeric|min:0',
            'bonus'            => 'required|numeric|min:0',
            'dependents_count' => 'nullable|integer|min:0|max:10',
        ]);

        $gross      = $data['base_salary'] + $data['allowance'] + $data['bonus'];
        $dependents = (int)($data['dependents_count'] ?? $item->dependents_count ?? 0);
        $bd         = $this->pit->breakdown($gross, $dependents);

        $item->update([
            'base_salary'      => $data['base_salary'],
            'allowance'        => $data['allowance'],
            'bonus'            => $data['bonus'],
            'gross_salary'     => $bd['gross_salary'],
            'insurance_base'   => $bd['insurance_base'],
            'bhxh_employee'    => $bd['bhxh_employee'],
            'bhyt_employee'    => $bd['bhyt_employee'],
            'bhtn_employee'    => $bd['bhtn_employee'],
            'bhxh_employer'    => $bd['bhxh_employer'],
            'bhyt_employer'    => $bd['bhyt_employer'],
            'bhtn_employer'    => $bd['bhtn_employer'],
            'pit'              => $bd['pit'],
            'dependents_count' => $dependents,
            'deductions'       => $bd['ins_employee'] + $bd['pit'],
            'net_salary'       => $bd['net_salary'],
        ]);

        $this->service->recalculateTotals($payroll);

        return back()->with('success', 'Đã cập nhật lương của nhân viên.');
    }

    public function confirm(Payroll $payroll): RedirectResponse
    {
        try {
            $this->service->confirmPayroll($payroll);
            return back()->with('success', 'Bảng lương tháng đã được xác nhận.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function payEmployee(Request $request, Payroll $payroll, PayrollItem $item): RedirectResponse
    {
        $data = $request->validate([
            'fund_id' => 'required|exists:funds,id',
        ]);

        try {
            $this->service->payEmployeeSalary($item, $data['fund_id']);
            return back()->with('success', "Đã thanh toán lương cho nhân viên {$item->user->name}.");
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Payroll $payroll): RedirectResponse
    {
        if ($payroll->status->value !== 'draft') {
            return back()->with('error', 'Chỉ có thể xóa bảng lương ở trạng thái nháp.');
        }

        $payroll->delete();

        return redirect()->route('accounting.payrolls.index')
            ->with('success', 'Bảng lương tháng đã được xóa.');
    }
}
