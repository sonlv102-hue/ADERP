<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\Fund;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FundController extends Controller
{
    public function index(): Response
    {
        $funds = Fund::orderBy('type')->orderBy('name')->get()
            ->map(fn (Fund $f) => [
                'id'              => $f->id,
                'code'            => $f->code,
                'name'            => $f->name,
                'type'            => $f->type,
                'type_label'      => $f->type === 'cash' ? 'Tiền mặt' : 'Ngân hàng',
                'bank_name'       => $f->bank_name,
                'bank_account_no' => $f->bank_account_no,
                'opening_balance' => (float) $f->opening_balance,
                'balance'         => $f->balance(),
                'is_active'       => $f->is_active,
            ]);

        return Inertia::render('Accounting/Funds/Index', ['funds' => $funds]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/Funds/Form', [
            'fund'     => null,
            'nextCode' => Fund::generateCode(),
            'accounts' => $this->detailAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'            => 'required|string|max:20|unique:funds,code',
            'name'            => 'required|string|max:255',
            'type'            => 'required|in:cash,bank',
            'account_code'    => ['nullable', 'string', Rule::exists('account_codes', 'code')->where('is_detail', true)],
            'bank_name'       => 'nullable|string|max:255',
            'bank_account_no' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        Fund::create($data);

        return redirect()->route('accounting.funds.index')
            ->with('success', 'Quỹ đã được tạo.');
    }

    public function edit(Fund $fund): Response
    {
        return Inertia::render('Accounting/Funds/Form', [
            'fund' => [
                'id'              => $fund->id,
                'code'            => $fund->code,
                'name'            => $fund->name,
                'type'            => $fund->type,
                'account_code'    => $fund->account_code,
                'bank_name'       => $fund->bank_name,
                'bank_account_no' => $fund->bank_account_no,
                'opening_balance' => (float) $fund->opening_balance,
                'is_active'       => $fund->is_active,
                'notes'           => $fund->notes,
            ],
            'accounts' => $this->detailAccounts(),
        ]);
    }

    public function update(Request $request, Fund $fund): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => 'required|in:cash,bank',
            'account_code'    => ['nullable', 'string', Rule::exists('account_codes', 'code')->where('is_detail', true)],
            'bank_name'       => 'nullable|string|max:255',
            'bank_account_no' => 'nullable|string|max:50',
            'opening_balance' => 'nullable|numeric|min:0',
            'is_active'       => 'boolean',
            'notes'           => 'nullable|string',
        ]);

        $fund->update($data);

        return redirect()->route('accounting.funds.index')
            ->with('success', 'Quỹ đã được cập nhật.');
    }

    private function detailAccounts(): array
    {
        return AccountCode::where('is_detail', true)
            ->whereIn('type', ['asset', 'liability'])
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name])
            ->toArray();
    }

    public function destroy(Fund $fund): RedirectResponse
    {
        if ($fund->cashVouchers()->exists() || $fund->payments()->exists()) {
            return back()->with('error', 'Không thể xóa quỹ đã có phát sinh.');
        }

        $fund->delete();

        return redirect()->route('accounting.funds.index')
            ->with('success', 'Đã xóa quỹ.');
    }
}
