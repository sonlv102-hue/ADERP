<?php

namespace Tests\Feature\Reports;

use App\Exports\InventoryIntegrity\InventoryIntegrityExport;
use App\Models\Customer;
use App\Models\InventoryIntegrityIssue as Issue;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Integrity\IntegrityReportQuery;
use App\Services\Inventory\Integrity\InventoryIntegrityAuditor;
use Database\Seeders\InventoryIntegrityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** V6 — giao việc, trạng thái, chấp nhận chênh lệch, chính sách, phân quyền, Excel. */
class InventoryIntegrityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->roles()->sync([Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true])->id]);
        foreach (['accounting' => 'Kế toán trưởng', 'warehouse' => 'Thủ kho', 'director' => 'Giám đốc'] as $code => $name) {
            Role::firstOrCreate(['code' => $code], ['name' => $name, 'is_system' => false]);
        }
        $this->seed(InventoryIntegrityPermissionSeeder::class);
        $acc = Permission::firstOrCreate(['code' => 'accounting.view'], ['module' => 'accounting', 'action' => 'view', 'name' => 'Xem kế toán']);
        Role::where('code', 'accounting')->first()->permissions()->syncWithoutDetaching([$acc->id]);
        $this->wh = Warehouse::create(['name' => 'Kho WF']);
    }

    private function userWithRole(?string $role): User
    {
        $u = User::factory()->create();
        if ($role) {
            $u->roles()->sync([Role::where('code', $role)->value('id')]);
        }
        return $u;
    }

    /** Một SP tồn ảo (TECHNICAL) và một SP hết hàng còn giá trị (ACCOUNTING). */
    private function seedIssues(): array
    {
        $ins = fn ($pid, $q, $amt) => DB::table('stock_movements')->insert(['product_id' => $pid, 'warehouse_id' => $this->wh->id, 'type' => 'in',
            'quantity' => $q, 'source_type' => 'opening_balance', 'source_id' => 0, 'created_by' => $this->admin->id, 'unit_cost' => 0, 'amount' => $amt,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $bal = fn ($pid, $q, $v) => DB::table('inventory_balances')->insert(['product_id' => $pid, 'warehouse_id' => $this->wh->id, 'qty_on_hand' => $q,
            'value_on_hand' => $v, 'avg_cost' => $q ? $v / $q : 0, 'initialized_from' => 'stock_entry', 'created_at' => now(), 'updated_at' => now()]);
        $tech = Product::create(['code' => 'WF-T', 'name' => 'Tồn ảo', 'is_active' => true]);
        $ins($tech->id, 10, 1000000);
        $bal($tech->id, 12, 1200000);
        $accP = Product::create(['code' => 'WF-A', 'name' => 'Hết hàng còn GT', 'is_active' => true]);
        $ins($accP->id, 0, 50000);
        $bal($accP->id, 0, 0);
        app(InventoryIntegrityAuditor::class)->run('all', null, $this->admin->id);
        return [Issue::where('rule_code', 'PHANTOM_STOCK')->firstOrFail(), Issue::where('rule_code', 'ZERO_QTY_WITH_VALUE')->firstOrFail()];
    }

    public function test_manual_resolved_is_rejected_and_accept_requires_justification_then_reopens_on_change(): void
    {
        [$tech] = $this->seedIssues();
        $kt = $this->userWithRole('accounting');
        $this->actingAs($kt);

        $this->patch(route('reports.inventory_integrity.status', $tech), ['status' => 'RESOLVED'])->assertSessionHasErrors('status');
        $this->patch(route('reports.inventory_integrity.status', $tech), ['status' => 'PENDING_TECHNICAL', 'note' => 'DEV kiểm tra'])->assertSessionHasNoErrors();
        $this->assertSame('PENDING_TECHNICAL', $tech->fresh()->status);

        $this->post(route('reports.inventory_integrity.accept', $tech), ['justification' => 'ok'])->assertSessionHasErrors('justification');
        $this->post(route('reports.inventory_integrity.accept', $tech), ['justification' => 'Đã kiểm kê thực tế, chấp nhận theo biên bản KK-01'])->assertSessionHasNoErrors();
        $tech->refresh();
        $this->assertSame('ACCEPTED_DIFFERENCE', $tech->status);
        $this->assertSame($kt->id, $tech->accepted_by);

        app(InventoryIntegrityAuditor::class)->run('all');
        $this->assertSame('ACCEPTED_DIFFERENCE', $tech->fresh()->status, 'số liệu không đổi → giữ chấp nhận');
        DB::table('inventory_balances')->where('product_id', $tech->product_id)->update(['qty_on_hand' => 15, 'value_on_hand' => 1500000]);
        app(InventoryIntegrityAuditor::class)->run('all');
        $this->assertSame('OPEN', $tech->fresh()->status, 'thay đổi sau chấp nhận → mở lại');
    }

    public function test_assign_note_resolution_and_history(): void
    {
        [$tech, $acc] = $this->seedIssues();
        $kt = $this->userWithRole('accounting');
        $this->actingAs($kt)->post(route('reports.inventory_integrity.assign'), ['ids' => [$tech->id, $acc->id], 'assignee_id' => $kt->id,
            'request_note' => 'Kiểm tra giúp', 'due_date' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->assertSame('ASSIGNED', $acc->fresh()->status);
        $this->assertSame($kt->id, $acc->fresh()->assignee_id);

        $this->post(route('reports.inventory_integrity.note', $acc), ['note' => 'Đang đối chiếu sổ cái'])->assertSessionHasNoErrors();
        $this->post(route('reports.inventory_integrity.resolution', $acc), ['check_result' => 'Chênh do giá vốn legacy',
            'chosen_option' => 'JE điều chỉnh', 'adjustment_ref' => 'BT-TEST'])->assertSessionHasNoErrors();
        $this->assertSame('BT-TEST', $acc->fresh()->resolution['adjustment_ref']);
        $this->assertSame(['detected', 'assigned', 'note', 'resolution'], $acc->logs()->reorder('id')->pluck('action')->all());
    }

    public function test_permissions_and_accounting_visibility(): void
    {
        [$tech, $acc] = $this->seedIssues();
        $this->actingAs($this->userWithRole(null))->get(route('reports.inventory_integrity.index'))->assertForbidden();

        $kho = $this->userWithRole('warehouse');
        $this->actingAs($kho);
        $this->get(route('reports.inventory_integrity.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/InventoryIntegrity/Index')
                ->where('issues.data', fn ($rows) => collect($rows)->pluck('category')->doesntContain('ACCOUNTING'))
                ->where('can.audit', false));
        $this->getJson(route('reports.inventory_integrity.show', $acc))->assertForbidden();
        $this->getJson(route('reports.inventory_integrity.show', $tech))->assertOk()->assertJsonPath('trace.journals', null);
        $this->post(route('reports.inventory_integrity.run'), ['scope' => 'all'])->assertForbidden();
        $this->post(route('reports.inventory_integrity.assign'), ['ids' => [$tech->id]])->assertForbidden();
        $this->get(route('reports.inventory_integrity.export'))->assertForbidden();

        $director = $this->userWithRole('director');
        $this->actingAs($director)->post(route('reports.inventory_integrity.accept', $tech), ['justification' => 'Giám đốc tự chấp nhận'])->assertForbidden();
    }

    public function test_excel_export_matches_screen_and_respects_permissions(): void
    {
        $this->freezeTime();
        [$tech, $acc] = $this->seedIssues();
        Excel::fake();
        $this->actingAs($this->userWithRole('accounting'))->get(route('reports.inventory_integrity.export', ['status' => 'open']))->assertOk();
        Excel::assertDownloaded('kiem-soat-sai-lech-ton-kho-' . now()->format('Ymd-His') . '.xlsx', function (InventoryIntegrityExport $e) use ($tech, $acc) {
            $sheets = $e->sheets();
            $qty = collect($sheets[1]->array())->pluck(0)->all();
            $accRows = collect($sheets[4]->array())->pluck(0)->all();
            return count($sheets) === 10 && $sheets[0]->title() === 'Tổng hợp'
                && in_array('VD-' . $tech->id, $qty, true) && in_array('VD-' . $acc->id, $accRows, true)
                && ! in_array('VD-' . $acc->id, $qty, true);
        });
    }

    public function test_policy_topics_and_returns_without_je_are_not_counted_as_product_issues(): void
    {
        $orderId = DB::table('orders')->insertGetId(['code' => 'DH-WF1', 'customer_id' => Customer::create(['code' => 'KH-WF', 'name' => 'KH'])->id,
            'order_date' => now()->toDateString(), 'status' => 'completed', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_returns')->insert(['code' => 'TH-WF1', 'order_id' => $orderId, 'warehouse_id' => $this->wh->id, 'return_date' => now()->toDateString(),
            'status' => 'confirmed', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        app(InventoryIntegrityAuditor::class)->run('all');

        $ret = Issue::where('rule_code', 'RETURN_WITHOUT_JE')->firstOrFail();
        $this->assertSame('POLICY_DECISION', $ret->category);
        $policy = Issue::where('source', 'policy')->where('document_ref', 'A4')->firstOrFail();
        $this->assertSame('PENDING_POLICY', $policy->status);
        $kpis = app(IntegrityReportQuery::class)->kpis($this->admin);
        $this->assertSame(0, $kpis['products_with_issues']);

        $this->actingAs($this->userWithRole('accounting'))
            ->post(route('reports.inventory_integrity.accept', $policy), ['justification' => 'Áp dụng Nợ 156/Có 632 cho trả hàng bán'])->assertSessionHasNoErrors();
        app(InventoryIntegrityAuditor::class)->run('all');
        $this->assertSame('RESOLVED', $policy->fresh()->status, 'quyết định chính sách không bị audit mở lại');
        $this->assertSame(1, Issue::where('source', 'policy')->where('document_ref', 'A4')->count());
    }

    public function test_accountant_with_individually_granted_permissions_without_admin_or_chief_role(): void
    {
        [$tech, $acc] = $this->seedIssues();
        $kt = $this->userWithRole(null);
        $codes = ['reports.inventory_integrity.view', 'reports.inventory_integrity.note', 'reports.inventory_integrity.export', 'reports.inventory_integrity.audit', 'accounting.view'];
        foreach ($codes as $code) {
            $kt->permissions()->attach(Permission::where('code', $code)->value('id'), ['effect' => 'allow']);
        }
        $this->actingAs($kt);

        $this->get(route('reports.inventory_integrity.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.accept', false)->where('can.assign', false)->where('can.note', true)
                ->where('issues.data', fn ($rows) => collect($rows)->pluck('id')->contains($acc->id)));
        $this->getJson(route('reports.inventory_integrity.show', $acc))->assertOk()->assertJsonPath('trace.accounting_hidden', false);
        $this->post(route('reports.inventory_integrity.note', $acc), ['note' => 'Kế toán viên ghi chú'])->assertSessionHasNoErrors();
        $this->post(route('reports.inventory_integrity.run'), ['scope' => 'issue', 'value' => (string) $acc->id])->assertSessionHas('success');
        Excel::fake();
        $this->get(route('reports.inventory_integrity.export'))->assertOk();

        $this->post(route('reports.inventory_integrity.accept', $acc), ['justification' => 'Kế toán viên tự chấp nhận'])->assertForbidden();
        $this->post(route('reports.inventory_integrity.assign'), ['ids' => [$acc->id], 'assignee_id' => $kt->id])->assertForbidden();
        $this->patch(route('reports.inventory_integrity.status', $acc), ['status' => 'IN_PROGRESS'])->assertForbidden();

        // Thu hồi quyền xem kế toán (deny) → vấn đề kế toán biến mất khỏi UI, drill-down và Excel
        $kt->permissions()->updateExistingPivot(Permission::where('code', 'accounting.view')->value('id'), ['effect' => 'deny']);
        $this->getJson(route('reports.inventory_integrity.show', $acc))->assertForbidden();
        $this->assertFalse(app(IntegrityReportQuery::class)->filtered($kt->fresh(), ['status' => 'all'])->pluck('id')->contains($acc->id));
    }

    public function test_permission_seeder_is_idempotent_and_only_adds(): void
    {
        $other = Permission::firstOrCreate(['code' => 'warehouse.view'], ['module' => 'warehouse', 'action' => 'view', 'name' => 'Xem kho']);
        $role = Role::where('code', 'warehouse')->first();
        $role->permissions()->syncWithoutDetaching([$other->id]);
        $before = ['perm' => Permission::count(), 'pivot' => DB::table('role_permissions')->count()];

        $this->seed(InventoryIntegrityPermissionSeeder::class);
        $this->seed(InventoryIntegrityPermissionSeeder::class);

        $this->assertSame($before, ['perm' => Permission::count(), 'pivot' => DB::table('role_permissions')->count()], 'chạy lại không thêm/xóa');
        $this->assertTrue($role->fresh()->permissions()->where('code', 'warehouse.view')->exists(), 'quyền sẵn có được giữ');
        $this->assertSame(6, Permission::where('code', 'like', 'reports.inventory_integrity.%')->count());
    }
}
