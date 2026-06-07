<?php

namespace App\Http\Controllers\Warehouse;

use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\SupplierImport;
use App\Models\PaymentTerm;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Warehouse/Suppliers/Index', [
            'suppliers' => Supplier::orderBy('code')->paginate(20)
                ->through(fn ($s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'phone' => $s->phone,
                    'email' => $s->email,
                    'is_active' => $s->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouse/Suppliers/Form', [
            'nextCode'      => Supplier::generateCode(),
            'payment_terms' => PaymentTerm::where('is_active', true)->orderBy('days')->get(['id', 'name', 'days']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'             => ['required', 'string', 'unique:suppliers,code'],
            'name'             => ['required', 'string', 'max:255'],
            'tax_code'         => ['nullable', 'string', 'max:50'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'email'            => ['nullable', 'email'],
            'address'          => ['nullable', 'string'],
            'bank_name'        => ['nullable', 'string', 'max:100'],
            'bank_account'     => ['nullable', 'string', 'max:50'],
            'bank_account_name'=> ['nullable', 'string', 'max:255'],
            'bank_branch'      => ['nullable', 'string', 'max:255'],
            'notes'            => ['nullable', 'string'],
            'payment_term_id'  => ['nullable', 'exists:payment_terms,id'],
        ]);

        Supplier::create($data);

        return redirect()->route('warehouse.suppliers.index')
            ->with('success', 'Đã tạo nhà cung cấp.');
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('Warehouse/Suppliers/Form', [
            'supplier' => [
                'id'               => $supplier->id,
                'code'             => $supplier->code,
                'name'             => $supplier->name,
                'tax_code'         => $supplier->tax_code,
                'phone'            => $supplier->phone,
                'email'            => $supplier->email,
                'address'          => $supplier->address,
                'bank_name'        => $supplier->bank_name,
                'bank_account'     => $supplier->bank_account,
                'bank_account_name'=> $supplier->bank_account_name,
                'bank_branch'      => $supplier->bank_branch,
                'notes'            => $supplier->notes,
                'is_active'        => $supplier->is_active,
                'payment_term_id'  => $supplier->payment_term_id,
            ],
            'payment_terms' => PaymentTerm::where('is_active', true)->orderBy('days')->get(['id', 'name', 'days']),
            'bankAccounts'  => $supplier->bankAccounts()->get()->map(fn ($b) => [
                'id'             => $b->id,
                'bank_name'      => $b->bank_name,
                'account_number' => $b->account_number,
                'account_name'   => $b->account_name,
                'branch'         => $b->branch,
                'is_primary'     => $b->is_primary,
                'is_active'      => $b->is_active,
            ]),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'code'             => ['required', 'string', 'unique:suppliers,code,' . $supplier->id],
            'name'             => ['required', 'string', 'max:255'],
            'tax_code'         => ['nullable', 'string', 'max:50'],
            'phone'            => ['nullable', 'string', 'max:20'],
            'email'            => ['nullable', 'email'],
            'address'          => ['nullable', 'string'],
            'bank_name'        => ['nullable', 'string', 'max:100'],
            'bank_account'     => ['nullable', 'string', 'max:50'],
            'bank_account_name'=> ['nullable', 'string', 'max:255'],
            'bank_branch'      => ['nullable', 'string', 'max:255'],
            'notes'            => ['nullable', 'string'],
            'is_active'        => ['boolean'],
            'payment_term_id'  => ['nullable', 'exists:payment_terms,id'],
        ]);

        $supplier->update($data);

        return redirect()->route('warehouse.suppliers.index')
            ->with('success', 'Đã cập nhật nhà cung cấp.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('warehouse.suppliers.index')
            ->with('success', 'Đã xóa nhà cung cấp.');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $import = new SupplierImport();
        Excel::import($import, $request->file('file'));

        if ($import->errors) {
            return back()->with('warning', 'Nhập ' . $import->imported . ' nhà cung cấp. Lỗi: ' . implode('; ', array_slice($import->errors, 0, 5)));
        }

        return back()->with('success', "Đã nhập {$import->imported} nhà cung cấp thành công.");
    }

    public function importTemplate()
    {
        $headers = [
            'name', 'phone', 'email', 'address', 'tax_code',
            'bank_name', 'account_number', 'account_name', 'branch',
            'notes',
        ];
        $sample = [
            ['Công ty XYZ', '0901234568', 'xyz@example.com', 'TP.HCM', '9876543210',
             'Techcombank', '0987654321', 'CÔNG TY CP XYZ', 'HCM - Quận 1', 'Nhà cung cấp mẫu'],
        ];
        return Excel::download(new TemplateExport($headers, 'Suppliers', $sample), 'supplier-template.xlsx');
    }
}
