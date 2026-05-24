<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'company_name',        'value' => 'Công ty TNHH Công nghệ Mini ERP',   'group' => 'company'],
            ['key' => 'company_address',      'value' => '123 Đường Nguyễn Văn A, Quận 1, TP.HCM', 'group' => 'company'],
            ['key' => 'company_phone',        'value' => '028.1234.5678',                     'group' => 'company'],
            ['key' => 'company_email',        'value' => 'info@minierp.local',                'group' => 'company'],
            ['key' => 'company_tax_code',     'value' => '0123456789',                        'group' => 'company'],
            ['key' => 'company_website',      'value' => 'www.minierp.local',                 'group' => 'company'],
            ['key' => 'company_logo',         'value' => null,                                'group' => 'company'],
            ['key' => 'company_description',  'value' => 'Kinh doanh và thi công giải pháp IT', 'group' => 'company'],
            ['key' => 'company_bank_name',    'value' => '',                                  'group' => 'company'],
            ['key' => 'company_bank_account', 'value' => '',                                  'group' => 'company'],
            ['key' => 'company_bank_branch',  'value' => '',                                  'group' => 'company'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'value'      => $setting['value'],
                    'group'      => $setting['group'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
