<?php

namespace Tests\Feature\Projects;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TC11: User không có quyền projects.manage → POST /direct-materials trả 403.
 */
class ProjectDirectMaterialPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_forbidden_without_projects_manage_permission(): void
    {
        Permission::firstOrCreate(['name' => 'projects.manage', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $customer = Customer::create(['code' => 'KH-T02', 'name' => 'KH Test 2', 'phone' => '0900000002']);
        $project  = Project::create([
            'code' => 'DA-TEST-2', 'name' => 'Dự án test 2', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $user->id,
        ]);

        $res = $this->post(route('projects.projects.direct-materials.store', $project->id), [
            'product_name'    => 'VT không quyền',
            'quantity'        => 1,
            'unit_price'      => 100000,
            'occurrence_date' => '2026-06-15',
            'handling_type'   => 'tracking_only',
        ]);

        $res->assertForbidden();
    }
}
