<?php

namespace Tests\Feature\Projects;

use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectSubcontract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * TC9: User không có quyền projects.subcontracts.acceptance.post → API trả 403.
 */
class ProjectSubcontractPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_store_forbidden_without_post_permission(): void
    {
        Permission::firstOrCreate(['name' => 'projects.subcontracts.acceptance.post', 'guard_name' => 'web']);

        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
        $customer = Customer::create(['code' => 'KH-SUB02', 'name' => 'KH Test Sub 2', 'phone' => '0900000011']);
        $project  = Project::create([
            'code' => 'DA-SUB-TEST-2', 'name' => 'Dự án test 2', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $user->id,
        ]);
        $subcontract = ProjectSubcontract::create([
            'project_id' => $project->id, 'contractor_name' => 'NCC Test', 'contractor_type' => 'company',
            'contract_no' => 'HDK-PERM-01', 'contract_date' => '2026-06-01', 'cost_group' => 'subcontractor',
            'amount_before_vat' => 10000000, 'total_amount' => 10000000, 'status' => 'active', 'created_by' => $user->id,
        ]);

        $res = $this->post(route('projects.projects.subcontracts.acceptances.store', [$project->id, $subcontract->id]), [
            'acceptance_date'   => '2026-06-10',
            'amount_before_vat' => 5000000,
        ]);

        $res->assertForbidden();
    }
}
