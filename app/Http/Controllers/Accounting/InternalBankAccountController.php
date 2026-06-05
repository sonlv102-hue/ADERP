<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\InternalBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InternalBankAccountController extends Controller
{
    public function index(): Response
    {
        $this->authorize('accounting.manage');

        return Inertia::render('Accounting/InternalBankAccounts/Index', [
            'accounts' => InternalBankAccount::orderBy('name')->get()->map(fn ($a) => [
                'id'             => $a->id,
                'name'           => $a->name,
                'account_number' => $a->account_number,
                'bank_name'      => $a->bank_name,
                'owner_name'     => $a->owner_name,
                'description'    => $a->description,
                'is_active'      => $a->is_active,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:50', 'unique:internal_bank_accounts,account_number'],
            'bank_name'      => ['nullable', 'string', 'max:100'],
            'owner_name'     => ['nullable', 'string', 'max:200'],
            'description'    => ['nullable', 'string', 'max:500'],
        ]);

        InternalBankAccount::create($data);

        return back()->with('success', 'Đã thêm tài khoản nội bộ.');
    }

    public function update(Request $request, InternalBankAccount $internalBankAccount): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:150'],
            'account_number' => ['required', 'string', 'max:50',
                "unique:internal_bank_accounts,account_number,{$internalBankAccount->id}"],
            'bank_name'      => ['nullable', 'string', 'max:100'],
            'owner_name'     => ['nullable', 'string', 'max:200'],
            'description'    => ['nullable', 'string', 'max:500'],
            'is_active'      => ['boolean'],
        ]);

        $internalBankAccount->update($data);

        return back()->with('success', 'Đã cập nhật.');
    }

    public function destroy(InternalBankAccount $internalBankAccount): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $internalBankAccount->delete();

        return back()->with('success', 'Đã xóa tài khoản nội bộ.');
    }
}
