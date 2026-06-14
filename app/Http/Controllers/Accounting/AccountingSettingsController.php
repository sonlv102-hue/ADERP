<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccountCode;
use App\Models\AccountingSetting;
use App\Services\AccountingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingSettingsController extends Controller
{
    private const GROUP_LABELS = [
        'cash'         => 'Tiền mặt & Ngân hàng',
        'inventory'    => 'Hàng tồn kho',
        'revenue'      => 'Doanh thu',
        'cogs'         => 'Giá vốn & Chi phí',
        'payroll'      => 'Lương & Bảo hiểm',
        'period_close' => 'Kết chuyển cuối kỳ',
        'project'      => 'Dự án (WIP)',
    ];

    public function index(): Response
    {
        $settings = AccountingSetting::orderBy('group')->orderBy('sort_order')->get();

        $grouped = [];
        foreach ($settings as $s) {
            $grouped[$s->group][] = [
                'key'         => $s->key,
                'value'       => $s->value,
                'label'       => $s->label,
                'description' => $s->description,
            ];
        }

        $groups = [];
        foreach (self::GROUP_LABELS as $key => $label) {
            if (isset($grouped[$key])) {
                $groups[] = ['key' => $key, 'label' => $label, 'settings' => $grouped[$key]];
            }
        }

        return Inertia::render('Accounting/Settings/Index', [
            'groups'   => $groups,
            'accounts' => AccountCode::where('is_active', true)
                ->where('is_detail', true)
                ->orderBy('code')
                ->get(['code', 'name', 'type']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('accounting.manage');

        $data = $request->validate([
            'settings'       => ['required', 'array'],
            'settings.*.key' => ['required', 'string', 'exists:accounting_settings,key'],
            'settings.*.value' => ['nullable', 'string', 'max:100', 'exists:account_codes,code'],
        ]);

        foreach ($data['settings'] as $item) {
            AccountingSetting::where('key', $item['key'])
                ->update(['value' => $item['value'] ?? null]);
        }

        AccountingSettings::clearCache();

        return back()->with('success', 'Đã lưu cài đặt tài khoản kế toán.');
    }
}
