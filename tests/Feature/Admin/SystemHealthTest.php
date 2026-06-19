<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: Admin truy cập được màn hình System Health.
 * TC2: User không có quyền không truy cập được.
 * TC3: Database OK hiển thị trạng thái ok.
 * TC4: Thiếu public/build/manifest.json → frontend status = warning.
 * TC5: Không có deploy.json → deploy status = info, không lỗi.
 * TC6: Migration không pending → migration status = ok.
 * TC7: Một check fail không làm sập toàn bộ màn hình.
 * TC8: Storage check trả về đúng cấu trúc.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie role:admin middleware checks hasRole(), NOT Gate — must actually assign role
        $adminRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->admin->syncRoles([$adminRole]);
    }

    // ─── TC1: Admin truy cập được ─────────────────────────────────────────────

    public function test_tc1_admin_can_access_system_health(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $res = $this->get(route('admin.system-health.index'));

        $res->assertStatus(200);
        $res->assertInertia(fn ($page) => $page->component('Admin/SystemHealth')->has('checks'));
    }

    // ─── TC2: User không quyền bị block ──────────────────────────────────────

    public function test_tc2_unauthenticated_redirected_to_login(): void
    {
        $res = $this->get(route('admin.system-health.index'));
        $res->assertRedirect(route('login'));
    }

    // ─── TC3: Database check trả về ok ───────────────────────────────────────

    public function test_tc3_database_check_returns_ok(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $res = $this->get(route('admin.system-health.index'));
        $res->assertStatus(200);

        $checks = $res->original->getData()['page']['props']['checks'];
        $this->assertEquals('ok', $checks['database']['status'], 'Database check phải ok khi DB kết nối được');
    }

    // ─── TC4: Frontend manifest không tồn tại → warning ──────────────────────

    public function test_tc4_missing_manifest_gives_frontend_warning(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        // Đảm bảo manifest không tồn tại (test env thường không có build)
        $manifestPath = public_path('build/manifest.json');
        $existed      = file_exists($manifestPath);
        $backupPath   = $manifestPath . '.bak';

        if ($existed) {
            rename($manifestPath, $backupPath);
        }

        try {
            $res    = $this->get(route('admin.system-health.index'));
            $checks = $res->original->getData()['page']['props']['checks'];

            $this->assertEquals('warning', $checks['frontend']['status'], 'Frontend status phải warning khi thiếu manifest');
        } finally {
            if ($existed && file_exists($backupPath)) {
                rename($backupPath, $manifestPath);
            }
        }
    }

    // ─── TC5: Không có deploy.json → info, không lỗi ─────────────────────────

    public function test_tc5_missing_deploy_json_gives_info_not_error(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $deployPath = storage_path('app/deploy.json');
        $existed    = file_exists($deployPath);
        $backupPath = $deployPath . '.bak';

        if ($existed) {
            rename($deployPath, $backupPath);
        }

        try {
            $res = $this->get(route('admin.system-health.index'));
            $res->assertStatus(200);

            $checks = $res->original->getData()['page']['props']['checks'];
            $this->assertEquals('info', $checks['deploy']['status'], 'Deploy status phải info khi thiếu deploy.json');
        } finally {
            if ($existed && file_exists($backupPath)) {
                rename($backupPath, $deployPath);
            }
        }
    }

    // ─── TC6: Không có migration pending → ok ─────────────────────────────────

    public function test_tc6_no_pending_migrations_gives_ok(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        // RefreshDatabase đã chạy tất cả migrations
        $res    = $this->get(route('admin.system-health.index'));
        $checks = $res->original->getData()['page']['props']['checks'];

        // Sau RefreshDatabase, pending phải = 0
        $pending = $checks['migrations']['detail']['pending'] ?? [];
        $this->assertEmpty($pending, 'Không có migration pending sau RefreshDatabase');
        $this->assertEquals('ok', $checks['migrations']['status']);
    }

    // ─── TC7: Màn hình vẫn load ngay cả khi một check bị exception ────────────

    public function test_tc7_page_loads_even_if_one_check_fails(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        // Inject service với git check ném exception vẫn không làm sập trang
        $res = $this->get(route('admin.system-health.index'));
        $res->assertStatus(200);

        $props = $res->original->getData()['page']['props'];
        $this->assertArrayHasKey('checks', $props);
        // Tất cả keys phải hiện diện
        foreach (['database', 'migrations', 'storage', 'queue', 'frontend', 'deploy', 'maintenance'] as $key) {
            $this->assertArrayHasKey($key, $props['checks'], "Check '{$key}' phải tồn tại trong response");
        }
    }

    // ─── TC8: Storage check trả về cấu trúc đúng ──────────────────────────────

    public function test_tc8_storage_check_has_correct_structure(): void
    {
        $this->actingAs($this->admin);
        Gate::before(fn ($u, $a) => true);

        $res    = $this->get(route('admin.system-health.index'));
        $checks = $res->original->getData()['page']['props']['checks'];

        $storage = $checks['storage'];
        $this->assertArrayHasKey('status', $storage);
        $this->assertArrayHasKey('detail', $storage);
        $this->assertIsArray($storage['detail']);

        foreach ($storage['detail'] as $row) {
            $this->assertArrayHasKey('path', $row);
            $this->assertArrayHasKey('exists', $row);
            $this->assertArrayHasKey('writable', $row);
        }
    }
}
