<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'is_primary' => ['boolean'],
        ]);

        if (! empty($data['is_primary'])) {
            $customer->contacts()->update(['is_primary' => false]);
        }

        $customer->contacts()->create($data);

        return back()->with('success', 'Đã thêm liên hệ.');
    }

    public function update(Request $request, Customer $customer, Contact $contact): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'is_primary' => ['boolean'],
        ]);

        if (! empty($data['is_primary'])) {
            $customer->contacts()->where('id', '!=', $contact->id)->update(['is_primary' => false]);
        }

        $contact->update($data);

        return back()->with('success', 'Đã cập nhật liên hệ.');
    }

    public function destroy(Customer $customer, Contact $contact): RedirectResponse
    {
        $contact->delete();

        return back()->with('success', 'Đã xóa liên hệ.');
    }
}
