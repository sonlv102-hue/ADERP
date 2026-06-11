<?php

namespace App\Http\Controllers\Crm;

use App\Enums\LeadStatus;
use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\CustomerImport;
use App\Models\AccountCode;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Crm/Customers/Index', [
            'customers' => Customer::with('assignedUser')
                ->orderBy('code')
                ->paginate(20)
                ->through(fn ($c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'company' => $c->company,
                    'phone' => $c->phone,
                    'email' => $c->email,
                    'is_fdi' => (bool) $c->is_fdi,
                    'lead_status' => $c->lead_status->value,
                    'lead_status_label' => $c->lead_status->label(),
                    'lead_status_color' => $c->lead_status->color(),
                    'assigned_user' => $c->assignedUser ? $c->assignedUser->name : null,
                ]),
            'lead_statuses' => collect(LeadStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'color' => $s->color(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Crm/Customers/Form', [
            'nextCode'           => Customer::generateCode(),
            'lead_statuses'      => collect(LeadStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'sales_users'        => User::role(['admin', 'sales', 'director'])->orderBy('name')->get(['id', 'name']),
            'payment_terms'      => PaymentTerm::where('is_active', true)->orderBy('days')->get(['id', 'name', 'days']),
            'receivable_accounts'=> $this->getReceivableAccountOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'            => ['required', 'string', 'unique:customers,code'],
            'name'            => ['required', 'string', 'max:255'],
            'company'         => ['nullable', 'string', 'max:255'],
            'tax_code'        => ['nullable', 'string', 'max:50'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'email'           => ['nullable', 'email'],
            'address'         => ['nullable', 'string'],
            'lead_status'     => ['required', 'string'],
            'assigned_to'     => ['nullable', 'exists:users,id'],
            'notes'           => ['nullable', 'string'],
            'payment_term_id'         => ['nullable', 'exists:payment_terms,id'],
            'credit_limit'            => ['nullable', 'numeric', 'min:0'],
            'is_fdi'                  => ['boolean'],
            'receivable_account_code' => ['required', Rule::exists('account_codes', 'code')->where('is_detail', true)],
        ]);

        Customer::create($data);

        return redirect()->route('crm.customers.index')
            ->with('success', 'Đã tạo khách hàng.');
    }

    public function show(Customer $customer): Response
    {
        return Inertia::render('Crm/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'company' => $customer->company,
                'tax_code' => $customer->tax_code,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'lead_status' => $customer->lead_status->value,
                'lead_status_label' => $customer->lead_status->label(),
                'lead_status_color' => $customer->lead_status->color(),
                'notes' => $customer->notes,
                'assigned_user' => $customer->assignedUser?->name,
                'contacts' => $customer->contacts->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'title' => $c->title,
                    'phone' => $c->phone,
                    'email' => $c->email,
                    'is_primary' => $c->is_primary,
                ]),
            ],
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('Crm/Customers/Form', [
            'customer' => [
                'id'                      => $customer->id,
                'code'                    => $customer->code,
                'name'                    => $customer->name,
                'company'                 => $customer->company,
                'tax_code'                => $customer->tax_code,
                'phone'                   => $customer->phone,
                'email'                   => $customer->email,
                'address'                 => $customer->address,
                'lead_status'             => $customer->lead_status->value,
                'assigned_to'             => $customer->assigned_to,
                'notes'                   => $customer->notes,
                'payment_term_id'         => $customer->payment_term_id,
                'credit_limit'            => $customer->credit_limit,
                'is_fdi'                  => (bool) $customer->is_fdi,
                'receivable_account_code' => $customer->receivable_account_code,
            ],
            'lead_statuses'       => collect(LeadStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'sales_users'         => User::role(['admin', 'sales', 'director'])->orderBy('name')->get(['id', 'name']),
            'payment_terms'       => PaymentTerm::where('is_active', true)->orderBy('days')->get(['id', 'name', 'days']),
            'receivable_accounts' => $this->getReceivableAccountOptions(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'code'            => ['required', 'string', 'unique:customers,code,' . $customer->id],
            'name'            => ['required', 'string', 'max:255'],
            'company'         => ['nullable', 'string', 'max:255'],
            'tax_code'        => ['nullable', 'string', 'max:50'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'email'           => ['nullable', 'email'],
            'address'         => ['nullable', 'string'],
            'lead_status'     => ['required', 'string'],
            'assigned_to'     => ['nullable', 'exists:users,id'],
            'notes'           => ['nullable', 'string'],
            'payment_term_id'         => ['nullable', 'exists:payment_terms,id'],
            'credit_limit'            => ['nullable', 'numeric', 'min:0'],
            'is_fdi'                  => ['boolean'],
            'receivable_account_code' => ['required', Rule::exists('account_codes', 'code')->where('is_detail', true)],
        ]);

        $customer->update($data);

        return redirect()->route('crm.customers.index')
            ->with('success', 'Đã cập nhật khách hàng.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()->route('crm.customers.index')
            ->with('success', 'Đã xóa khách hàng.');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);

        $import = new CustomerImport();
        Excel::import($import, $request->file('file'));

        if ($import->errors) {
            return back()->with('warning', 'Nhập ' . $import->imported . ' khách hàng. Lỗi: ' . implode('; ', array_slice($import->errors, 0, 5)));
        }

        return back()->with('success', "Đã nhập {$import->imported} khách hàng thành công.");
    }

    public function importTemplate()
    {
        $headers = [
            'name', 'phone', 'email', 'address', 'tax_code',
            'bank_name', 'account_number', 'account_name', 'branch',
            'notes',
        ];
        $sample = [
            ['Công ty ABC', '0901234567', 'abc@example.com', 'Hà Nội', '0123456789',
             'Vietcombank', '1234567890', 'CÔNG TY TNHH ABC', 'HN - Hoàn Kiếm', 'Khách hàng mẫu'],
        ];
        return Excel::download(new TemplateExport($headers, 'Customers', $sample), 'customer-template.xlsx');
    }

    private function getReceivableAccountOptions(): array
    {
        return AccountCode::where('is_detail', true)
            ->where('code', 'like', '13%')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }
}
