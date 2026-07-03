<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Cập nhật report_signing_place lưu đúng vào settings group=company
 * TC2: Bỏ trống report_signing_place -> lưu chuỗi rỗng, không lỗi
 */
class SettingsReportSigningPlaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->syncRoles([$adminRole]);
        $this->actingAs($user);
    }

    public function test_update_saves_report_signing_place(): void
    {
        $response = $this->post(route('admin.settings.update'), [
            'company_name'         => 'Test Co',
            'report_signing_place' => 'Hải Phòng',
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        $this->assertSame('Hải Phòng', Setting::get('report_signing_place'));
    }

    public function test_update_allows_blank_signing_place(): void
    {
        Setting::set('report_signing_place', 'Hà Nội', 'company');

        $response = $this->post(route('admin.settings.update'), [
            'company_name'         => 'Test Co',
            'report_signing_place' => '',
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        // Laravel's ConvertEmptyStringsToNull middleware turns '' into null before
        // it reaches the controller — both are treated as "not configured" (see
        // docs/REPORTING_STANDARDS.md §3), so assert emptiness rather than an exact ''.
        $this->assertEmpty(Setting::get('report_signing_place'));
    }
}
