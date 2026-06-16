<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = BankAccount::orderBy('name')->get()->map(fn ($a) => [
            'id'              => $a->id,
            'name'            => $a->name,
            'bank_name'       => $a->bank_name,
            'account_number'  => $a->account_number,
            'account_code'    => $a->account_code,
            'is_active'       => $a->is_active,
            'current_balance' => $a->currentBalance(),
        ]);

        return Inertia::render('Accounting/BankAccounts/Index', ['accounts' => $accounts]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounting/BankAccounts/Form', [
            'account'  => null,
            'accounts' => $this->detailAccounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'bank_name'       => 'required|string|max:100',
            'account_number'  => 'required|string|max:50',
            'account_code'    => ['required', 'string', Rule::exists('account_codes', 'code')->where('is_detail', true)],
            'opening_balance' => 'nullable|numeric',
            'notes'           => 'nullable|string',
        ]);

        BankAccount::create([...$data, 'created_by' => auth()->id()]);

        return redirect()->route('accounting.bank-accounts.index')->with('success', 'Đã tạo tài khoản ngân hàng.');
    }

    public function show(BankAccount $bankAccount): Response
    {
        return redirect()->route('accounting.bank-accounts.transactions.index', $bankAccount);
    }

    public function edit(BankAccount $bankAccount): Response
    {
        return Inertia::render('Accounting/BankAccounts/Form', [
            'accounts' => $this->detailAccounts(),
            'account' => [
                'id'              => $bankAccount->id,
                'name'            => $bankAccount->name,
                'bank_name'       => $bankAccount->bank_name,
                'account_number'  => $bankAccount->account_number,
                'account_code'    => $bankAccount->account_code,
                'opening_balance' => $bankAccount->opening_balance,
                'is_active'       => $bankAccount->is_active,
                'notes'           => $bankAccount->notes,
            ],
        ]);
    }

    public function update(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'bank_name'       => 'required|string|max:100',
            'account_number'  => 'required|string|max:50',
            'account_code'    => ['required', 'string', Rule::exists('account_codes', 'code')->where('is_detail', true)],
            'opening_balance' => 'nullable|numeric',
            'is_active'       => 'boolean',
            'notes'           => 'nullable|string',
        ]);

        $bankAccount->update($data);

        return redirect()->route('accounting.bank-accounts.index')->with('success', 'Đã cập nhật.');
    }

    private function detailAccounts(): array
    {
        return \App\Models\AccountCode::where('is_detail', true)
            ->whereIn('type', ['asset'])
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name])
            ->toArray();
    }
}
