<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(SettingsSeeder::class);
        $this->call(DocumentTypeSeeder::class);
        $this->call(AccountCodeSeeder::class);
        $this->call(PitConfigSeeder::class);

        // Admin
        $admin = User::firstOrCreate(
            ['email' => 'admin@minierp.local'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('Admin@123'),
                'phone' => '0901234567',
                'is_active' => true,
            ]
        );
        $admin->syncRoles(['admin']);

        // Tài khoản demo cho từng role
        $demoUsers = [
            ['email' => 'director@minierp.local', 'name' => 'Giám đốc Demo',  'role' => 'director'],
            ['email' => 'sales@minierp.local',    'name' => 'Kinh doanh Demo', 'role' => 'sales'],
            ['email' => 'kho@minierp.local',      'name' => 'Kho Demo',        'role' => 'warehouse'],
            ['email' => 'kt@minierp.local',       'name' => 'Kỹ thuật Demo',   'role' => 'technical'],
            ['email' => 'ketoan@minierp.local',   'name' => 'Kế toán Demo',    'role' => 'accounting'],
            ['email' => 'cskh@minierp.local',     'name' => 'CSKH Demo',       'role' => 'cskh'],
        ];

        foreach ($demoUsers as $demo) {
            $user = User::firstOrCreate(
                ['email' => $demo['email']],
                ['name' => $demo['name'], 'password' => Hash::make('Demo@123'), 'is_active' => true]
            );
            $user->syncRoles([$demo['role']]);
        }

        $this->call(DemoDataSeeder::class);
    }
}
