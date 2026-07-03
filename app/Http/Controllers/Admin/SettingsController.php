<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => Setting::getGroup('company'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'company_name'        => 'required|string|max:200',
            'company_address'     => 'nullable|string|max:500',
            'company_phone'       => 'nullable|string|max:50',
            'company_email'       => 'nullable|email|max:200',
            'company_tax_code'    => 'nullable|string|max:50',
            'company_website'     => 'nullable|string|max:200',
            'company_description' => 'nullable|string|max:500',
            'company_bank_name'   => 'nullable|string|max:200',
            'company_bank_account'=> 'nullable|string|max:50',
            'company_bank_branch' => 'nullable|string|max:200',
            'report_signing_place'=> 'nullable|string|max:100',
            'logo'                => 'nullable|image|mimes:png,jpg,jpeg,svg|max:2048',
        ]);

        $fields = [
            'company_name', 'company_address', 'company_phone', 'company_email',
            'company_tax_code', 'company_website', 'company_description',
            'company_bank_name', 'company_bank_account', 'company_bank_branch',
            'report_signing_place',
        ];

        foreach ($fields as $field) {
            Setting::set($field, $request->input($field, ''), 'company');
        }

        if ($request->hasFile('logo')) {
            $old = Setting::get('company_logo');
            if ($old) {
                $oldPath = str_replace('/storage/', '', $old);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('logo')->store('logos', 'public');
            Setting::set('company_logo', Storage::url($path), 'company');
        }

        Setting::forgetCache('company');

        return redirect()->route('admin.settings.index')->with('success', 'Cài đặt công ty đã được cập nhật.');
    }

    public function deleteLogo(): RedirectResponse
    {
        $logo = Setting::get('company_logo');
        if ($logo) {
            $path = str_replace('/storage/', '', $logo);
            Storage::disk('public')->delete($path);
            Setting::set('company_logo', null, 'company');
            Setting::forgetCache('company');
        }

        return redirect()->route('admin.settings.index')->with('success', 'Logo đã được xoá.');
    }
}
