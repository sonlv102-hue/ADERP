<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountCodeController extends Controller
{
    public function index(): Response
    {
        $accounts = AccountCode::orderBy('code')->get()
            ->map(fn ($a) => [
                'code'           => $a->code,
                'name'           => $a->name,
                'type'           => $a->type,
                'type_label'     => $a->typeLabel(),
                'normal_balance' => $a->normal_balance,
                'parent_code'    => $a->parent_code,
                'level'          => $a->level,
                'is_detail'      => $a->is_detail,
                'is_active'      => $a->is_active,
            ]);

        return Inertia::render('Accounting/AccountCodes/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'max:10', 'unique:account_codes,code'],
            'name'           => ['required', 'string', 'max:200'],
            'type'           => ['required', 'in:asset,liability,equity,revenue,expense,contra'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'parent_code'    => ['nullable', 'exists:account_codes,code'],
            'is_detail'      => ['boolean'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $level = 1;
        if ($data['parent_code'] ?? null) {
            $parent = AccountCode::find($data['parent_code']);
            $level  = $parent ? $parent->level + 1 : 1;
        }

        AccountCode::create([...$data, 'level' => $level]);

        return back()->with('success', 'Đã thêm tài khoản kế toán.');
    }

    public function update(Request $request, AccountCode $accountCode): RedirectResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:200'],
            'is_detail' => ['boolean'],
            'is_active' => ['boolean'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $accountCode->update($data);

        return back()->with('success', 'Đã cập nhật tài khoản.');
    }

    public function destroy(AccountCode $accountCode): RedirectResponse
    {
        if ($accountCode->journalLines()->exists()) {
            return back()->with('error', 'Không thể xóa tài khoản đã có bút toán.');
        }
        if ($accountCode->children()->exists()) {
            return back()->with('error', 'Không thể xóa tài khoản đã có tài khoản con.');
        }

        $accountCode->delete();

        return back()->with('success', 'Đã xóa tài khoản.');
    }
}
