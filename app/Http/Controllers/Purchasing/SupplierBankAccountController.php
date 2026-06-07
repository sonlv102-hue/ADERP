<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierBankAccountController extends Controller
{
    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('purchasing.create');

        $data = $request->validate([
            'bank_name'      => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_name'   => ['nullable', 'string', 'max:200'],
            'branch'         => ['nullable', 'string', 'max:150'],
            'is_primary'     => ['boolean'],
        ]);

        if (!empty($data['is_primary'])) {
            $supplier->bankAccounts()->update(['is_primary' => false]);
        }

        $supplier->bankAccounts()->create($data);

        return back()->with('success', 'Đã thêm tài khoản ngân hàng.');
    }

    public function update(Request $request, Supplier $supplier, SupplierBankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('purchasing.create');
        abort_if($bankAccount->supplier_id !== $supplier->id, 403);

        $data = $request->validate([
            'bank_name'      => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50'],
            'account_name'   => ['nullable', 'string', 'max:200'],
            'branch'         => ['nullable', 'string', 'max:150'],
            'is_primary'     => ['boolean'],
        ]);

        if (!empty($data['is_primary'])) {
            $supplier->bankAccounts()->where('id', '!=', $bankAccount->id)->update(['is_primary' => false]);
        }

        $bankAccount->update($data);

        return back()->with('success', 'Đã cập nhật tài khoản ngân hàng.');
    }

    public function destroy(Supplier $supplier, SupplierBankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('purchasing.create');
        abort_if($bankAccount->supplier_id !== $supplier->id, 403);

        $count = $bankAccount->bankTransactions()->count();
        if ($count > 0) {
            return back()->withErrors(['error' => "Không thể xóa: tài khoản đang được tham chiếu bởi {$count} giao dịch ngân hàng."]);
        }

        $bankAccount->delete();

        return back()->with('success', 'Đã xóa tài khoản ngân hàng.');
    }

    public function setPrimary(Supplier $supplier, SupplierBankAccount $bankAccount): RedirectResponse
    {
        $this->authorize('purchasing.create');
        abort_if($bankAccount->supplier_id !== $supplier->id, 403);

        $supplier->bankAccounts()->update(['is_primary' => false]);
        $bankAccount->update(['is_primary' => true]);

        return back()->with('success', 'Đã đặt làm tài khoản mặc định.');
    }
}
