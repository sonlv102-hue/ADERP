<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounting_settings')->upsert(
            [[
                'key'         => 'project_equipment_account',
                'value'       => '6237',
                'label'       => 'TK chi phí máy thi công (dịch vụ thuê ngoài)',
                'description' => 'TK mặc định bên Nợ cho chi phí máy thi công (phân loại Máy thi công)',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]],
            ['key'],
            ['value', 'label', 'description', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('accounting_settings')->where('key', 'project_equipment_account')->delete();
    }
};
