<?php

namespace Tests\Feature\Reports;

use App\Models\InventoryIntegrityIssue as Issue;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Integrity\InventoryIntegrityAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** V6 release — import phương án: định danh nghiệp vụ, idempotent, không ghi đè, đánh dấu cần xác minh lại. */
class InventoryIntegrityCasesCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->roles()->sync([Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true])->id]);
        $this->actingAs($admin);
        $wh = Warehouse::create(['name' => 'Kho CS']);
        foreach (['CS-1' => 12, 'CS-2' => 11] as $code => $avco) {   // tồn ảo 2 và 1
            $p = Product::create(['code' => $code, 'name' => $code, 'is_active' => true]);
            DB::table('stock_movements')->insert(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'type' => 'in', 'quantity' => 10,
                'source_type' => 'opening_balance', 'source_id' => 0, 'created_by' => $admin->id, 'unit_cost' => 100, 'amount' => 1000,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_balances')->insert(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'qty_on_hand' => $avco, 'value_on_hand' => $avco * 100,
                'avg_cost' => 100, 'initialized_from' => 'stock_entry', 'created_at' => now(), 'updated_at' => now()]);
        }
        app(InventoryIntegrityAuditor::class)->run('all');
        $this->file = tempnam(sys_get_temp_dir(), 'cases');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function cases(float $qtySum): void
    {
        file_put_contents($this->file, json_encode([[
            'id' => 'T-1', 'match' => ['rule' => 'PHANTOM_STOCK', 'product_codes' => ['CS-1', 'CS-2']],
            'expect' => ['count' => 2, 'qty_diff_sum' => $qtySum, 'tolerance' => 0.001],
            'case' => ['title' => 'Phương án thử', 'summary' => 'Tóm tắt'], 'category' => 'ACCOUNTING', 'status' => 'PENDING_ACCOUNTING',
        ]]));
    }

    public function test_attach_is_idempotent_and_never_overwrites_user_work(): void
    {
        $issues = Issue::where('rule_code', 'PHANTOM_STOCK')->orderBy('id')->get();
        $this->assertCount(2, $issues);
        $issues[1]->update(['status' => 'IN_PROGRESS', 'resolution' => ['check_result' => 'Kế toán đã kiểm tra']]);

        $this->cases(3);
        $this->artisan('inventory:integrity-cases', ['file' => $this->file])->assertSuccessful();
        $logs = DB::table('inventory_integrity_issue_logs')->count();
        $this->artisan('inventory:integrity-cases', ['file' => $this->file])->assertSuccessful();
        $this->assertSame($logs, DB::table('inventory_integrity_issue_logs')->count(), 'chạy lại không sinh log/bản ghi trùng');
        $this->assertSame(2, Issue::where('rule_code', 'PHANTOM_STOCK')->count());

        $a = $issues[0]->fresh();
        $b = $issues[1]->fresh();
        $this->assertSame('PENDING_ACCOUNTING', $a->status);
        $this->assertSame('T-1', $a->case_info['case_id']);
        $this->assertFalse($a->case_info['needs_reverify']);
        $this->assertSame('IN_PROGRESS', $b->status, 'không ghi đè trạng thái người dùng đã đặt');
        $this->assertSame('Kế toán đã kiểm tra', $b->resolution['check_result'], 'không ghi đè kết quả xử lý');
    }

    public function test_changed_production_data_marks_case_for_reverification(): void
    {
        $this->cases(99);   // số liệu lúc lập phương án khác dữ liệu hiện tại
        $this->artisan('inventory:integrity-cases', ['file' => $this->file])->assertSuccessful();
        $issue = Issue::where('rule_code', 'PHANTOM_STOCK')->first();
        $this->assertTrue($issue->case_info['needs_reverify']);
        $this->assertStringContainsString('qty_diff', $issue->case_info['reverify_reason']);
        $this->assertSame('PENDING_VERIFICATION', $issue->status);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->cases(3);
        $this->artisan('inventory:integrity-cases', ['file' => $this->file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Issue::whereNotNull('case_info')->count());
    }
}
