<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'            => 'required|string|max:20|unique:funds,code',
            'name'            => 'required|string|max:255',
            'type'            => 'required|in:cash,bank',
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
                'bank_name'       => $fund->bank_name,
                'bank_account_no' => $fund->bank_account_no,
                'opening_balance' => (float) $fund->opening_balance,
                'is_active'       => $fund->is_active,
                'notes'           => $fund->notes,
            ],
        ]);
    }

    public function update(Request $request, Fund $fund): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'type'            => 'required|in:cash,bank',
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
