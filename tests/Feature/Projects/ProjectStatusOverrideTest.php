<?php

namespace Tests\Feature\Projects;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Sửa trạng thái dự án thủ công qua PATCH projects.projects.status.
 */
class ProjectStatusOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::firstOrCreate(
            ['email' => 'proj-status@test.local'],
            ['name' => 'Proj Status', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($u) => $u->email === 'proj-status@test.local' ? true : null);

        $customer = Customer::create(['code' => 'KH-PST01', 'name' => 'KH Status', 'phone' => '0900000099']);
        $this->project = Project::create([
            'code' => 'DA-PST-01', 'name' => 'Dự án status', 'status' => 'completed',
            'actual_end_date' => '2026-10-01', 'customer_id' => $customer->id, 'created_by' => $user->id,
        ]);
    }

    private function patchStatus(array $payload)
    {
        return $this->patch(route('projects.projects.status', $this->project), $payload);
    }

    public function test_reopen_completed_project_clears_end_date_and_logs(): void
    {
        $this->patchStatus(['status' => 'in_progress', 'reason' => 'Bổ sung chi phí'])
            ->assertSessionHas('success');

        $this->project->refresh();
        $this->assertSame('in_progress', $this->project->status->value);
        $this->assertNull($this->project->actual_end_date);

        $log = Activity::where('subject_type', Project::class)->where('subject_id', $this->project->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Bổ sung chi phí', $log->properties['reason']);
        $this->assertSame('completed', $log->properties['old_status']);
    }

    public function test_cancelled_can_go_back_to_planning(): void
    {
        $this->project->update(['status' => 'cancelled', 'actual_end_date' => null]);

        $this->patchStatus(['status' => 'planning', 'reason' => 'Hủy nhầm'])->assertSessionHas('success');

        $this->assertSame('planning', $this->project->fresh()->status->value);
    }

    public function test_set_completed_fills_end_date(): void
    {
        $this->project->update(['status' => 'on_hold', 'actual_end_date' => null]);

        $this->patchStatus(['status' => 'completed', 'reason' => 'Đã nghiệm thu'])->assertSessionHas('success');

        $this->assertNotNull($this->project->fresh()->actual_end_date);
    }

    public function test_reason_required_and_status_must_be_valid(): void
    {
        $this->patchStatus(['status' => 'in_progress', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->patchStatus(['status' => 'bogus', 'reason' => 'x'])->assertSessionHasErrors('status');

        $this->assertSame('completed', $this->project->fresh()->status->value);
    }

    public function test_same_status_is_rejected(): void
    {
        $this->patchStatus(['status' => 'completed', 'reason' => 'x'])->assertSessionHas('error');
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $other = User::create(['name' => 'No Perm', 'email' => 'noperm-pst@test.local', 'password' => bcrypt('p'), 'is_active' => true]);
        $this->actingAs($other);

        $this->patchStatus(['status' => 'in_progress', 'reason' => 'x'])->assertForbidden();
    }
}
