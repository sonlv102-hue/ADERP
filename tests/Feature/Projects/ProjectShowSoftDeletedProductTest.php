<?php

namespace Tests\Feature\Projects;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Project;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Regression: GET projects.show crash 500 khi 1 dòng stock_exit_item (issue_purpose=project_cost)
 * tham chiếu sản phẩm đã bị soft-delete. Xem production log 2026-10-04/07, project_id=1,
 * stock_exit_item id=138 product_id=88 (TQL-1U, deleted_at=2026-08-31).
 */
class ProjectShowSoftDeletedProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_does_not_crash_when_stock_exit_item_product_is_soft_deleted(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn () => true);

        $customer  = Customer::create(['code' => 'KH-SD01', 'name' => 'KH Test', 'is_active' => true]);
        $warehouse = Warehouse::create(['name' => 'Kho Test', 'code' => 'KT-SD01']);
        $product   = Product::create([
            'code' => 'SP-SD01', 'name' => 'Sản phẩm sẽ bị xóa', 'unit' => 'cái',
            'cost_price' => 100000, 'is_active' => true,
        ]);
        $project = Project::create([
            'code' => 'DA-SD01', 'name' => 'Dự án test', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'created_by' => $user->id,
        ]);

        $exit = StockExit::create([
            'code' => 'XK-SD01', 'warehouse_id' => $warehouse->id, 'project_id' => $project->id,
            'issue_purpose' => 'project_cost', 'exit_date' => '2026-06-15',
            'status' => 'confirmed', 'created_by' => $user->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $product->id,
            'quantity' => 2, 'unit_price' => 0, 'source_cost' => 100000, 'total_cost' => 200000,
        ]);

        $product->delete();

        $res = $this->get(route('projects.projects.show', $project->id));

        $res->assertStatus(200);
        $res->assertInertia(fn ($page) => $page
            ->where('stockExitItems.0.product_code', 'SP-SD01')
            ->where('stockExitItems.0.product_name', 'Sản phẩm sẽ bị xóa')
        );
    }
}
