<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC-A: Tạo user với password mạnh → DB không lưu plaintext.
 * TC-B: Hash::check() với password gốc phải TRUE.
 * TC-C: Đổi password → hash thay đổi và Hash::check() với password mới TRUE.
 * TC-D: Password không xuất hiện trong $user->toArray().
 * TC-E: Response admin.users.edit không chứa password/remember_token.
 * TC-F: Login sai 5 lần bị chặn tạm thời (rate limit theo email+IP).
 * TC-G: Login đúng sau các lần sai reset lại rate limit counter.
 */
class PasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Role $memberRole;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('AdminPass123'), 'is_active' => true]
        );
        $this->admin->syncRoles([$adminRole]);

        $this->memberRole = Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);
    }

    public function test_tc_a_new_user_password_not_stored_as_plaintext(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.users.store'), [
            'name'     => 'Nguyen Van A',
            'email'    => 'nva@test.local',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'role_ids' => [$this->memberRole->id],
        ]);

        $stored = User::where('email', 'nva@test.local')->firstOrFail()->getRawOriginal('password');

        $this->assertNotSame('StrongPassword123!', $stored);
        $this->assertSame('bcrypt', password_get_info($stored)['algoName'] ?? null);
    }

    public function test_tc_b_hash_check_succeeds_with_original_password(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.users.store'), [
            'name'     => 'Nguyen Van B',
            'email'    => 'nvb@test.local',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'role_ids' => [$this->memberRole->id],
        ]);

        $stored = User::where('email', 'nvb@test.local')->firstOrFail()->getRawOriginal('password');

        $this->assertTrue(Hash::check('StrongPassword123!', $stored));
    }

    public function test_tc_c_updating_password_changes_hash_and_verifies(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.users.store'), [
            'name'     => 'Nguyen Van C',
            'email'    => 'nvc@test.local',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
            'role_ids' => [$this->memberRole->id],
        ]);

        $user = User::where('email', 'nvc@test.local')->firstOrFail();
        $originalHash = $user->getRawOriginal('password');

        $this->put(route('admin.users.update', $user), [
            'name'     => $user->name,
            'email'    => $user->email,
            'password' => 'NewStrongPassword456!',
            'password_confirmation' => 'NewStrongPassword456!',
            'role_ids' => [$this->memberRole->id],
        ]);

        $newHash = $user->fresh()->getRawOriginal('password');

        $this->assertNotSame($originalHash, $newHash);
        $this->assertTrue(Hash::check('NewStrongPassword456!', $newHash));
    }

    public function test_tc_d_password_not_present_in_to_array(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_tc_e_edit_response_does_not_expose_password_fields(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.users.edit', $target))
            ->assertInertia(function ($page) {
                $page->missing('user.password')
                    ->missing('user.remember_token')
                    ->etc();
            });
    }

    public function test_tc_f_login_blocked_after_five_failed_attempts(): void
    {
        $user = User::factory()->create([
            'email'     => 'ratelimit@test.local',
            'password'  => bcrypt('CorrectPassword123'),
            'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), [
                'email'    => $user->email,
                'password' => 'WrongPassword',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'CorrectPassword123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_tc_g_successful_login_clears_rate_limit_counter(): void
    {
        $user = User::factory()->create([
            'email'     => 'resetlimit@test.local',
            'password'  => bcrypt('CorrectPassword123'),
            'is_active' => true,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login'), [
                'email'    => $user->email,
                'password' => 'WrongPassword',
            ]);
        }

        $this->post(route('login'), [
            'email'    => $user->email,
            'password' => 'CorrectPassword123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $key = strtolower($user->email) . '|127.0.0.1';
        $this->assertSame(0, RateLimiter::attempts($key));
    }
}
