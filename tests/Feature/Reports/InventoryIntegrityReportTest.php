<?php

namespace Tests\Feature\Reports;

use App\Models\InventoryAuditRun;
use App\Models\InventoryIntegrityIssue as Issue;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Integrity\IntegrityFindingCollector;
use App\Services\Inventory\Integrity\InventoryIntegrityAuditor;
use Database\Seeders\InventoryIntegrityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** V6 — audit engine: phát hiện, không sinh trùng, tự đóng/mở lại, phạm vi nhỏ, audit lỗi, không cộng trùng giá trị. */
class InventoryIntegrityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $wh;
    private Warehouse $wh2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->roles()->sync([Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true])->id]);
        $this->actingAs($this->admin);
        $this->wh = Warehouse::create(['name' => 'Kho IR']);
        $this->wh2 = Warehouse::create(['name' => 'Kho IR 2']);
    }

    private function stocked(string $code, float $qty = 10, float $cost = 100000, ?Warehouse $wh = null): Product
    {
        $p = Product::create(['code' => $code, 'name' => "Hàng {$code}", 'cost_price' => $cost, 'is_active' => true]);
        $w = ($wh ?? $this->wh)->id;
        $this->movement($p->id, $w, $qty, $cost);
        DB::table('inventory_balances')->insert(['product_id' => $p->id, 'warehouse_id' => $w, 'qty_on_hand' => $qty,
            'value_on_hand' => $qty * $cost, 'avg_cost' => $cost, 'initialized_from' => 'stock_entry', 'created_at' => now(), 'updated_at' => now()]);
        return $p;
    }

    private function movement(int $pid, int $wid, float $qty, float $cost, array $extra = []): int
    {
        return DB::table('stock_movements')->insertGetId($extra + ['product_id' => $pid, 'warehouse_id' => $wid, 'type' => $qty >= 0 ? 'in' : 'out',
            'quantity' => $qty, 'source_type' => 'opening_balance', 'source_id' => 0, 'created_by' => $this->admin->id, 'unit_cost' => $cost,
            'amount' => $qty * $cost, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function setBalance(Product $p, float $qty, ?Warehouse $wh = null): void
    {
        DB::table('inventory_balances')->where('product_id', $p->id)->where('warehouse_id', ($wh ?? $this->wh)->id)
            ->update(['qty_on_hand' => $qty, 'value_on_hand' => $qty * 100000]);
    }

    private function audit(string $scope = 'all', ?string $value = null): InventoryAuditRun
    {
        $run = app(InventoryIntegrityAuditor::class)->run($scope, $value, $this->admin->id);
        $this->assertSame('success', $run->status, (string) $run->error);
        return $run;
    }

    private function issue(string $rule, Product $p): ?Issue
    {
        return Issue::where('rule_code', $rule)->where('product_id', $p->id)->first();
    }

    public function test_detects_no_duplicates_auto_resolves_and_reopens_with_history(): void
    {
        $a = $this->stocked('IR-A');
        $this->audit();
        $this->assertNull($this->issue('PHANTOM_STOCK', $a));

        $this->setBalance($a, 12);                       // tồn ảo 2
        $this->audit();
        $this->audit();
        $this->assertSame(1, Issue::where('rule_code', 'PHANTOM_STOCK')->where('product_id', $a->id)->count(), 'không sinh trùng');
        $issue = $this->issue('PHANTOM_STOCK', $a);
        $this->assertSame('OPEN', $issue->status);
        $this->assertEquals(2, (float) $issue->qty_diff);
        $this->assertEquals(10, (float) $issue->qty_book);

        $this->setBalance($a, 10);
        $run = $this->audit('product', 'IR-A');
        $issue->refresh();
        $this->assertSame('RESOLVED', $issue->status);
        $this->assertSame($run->id, $issue->resolved_run_id);

        $this->setBalance($a, 13);                       // tái phát
        $this->audit();
        $issue->refresh();
        $this->assertSame('OPEN', $issue->status);
        $this->assertSame(1, $issue->reopen_count);
        $this->assertSame(['detected', 'auto_resolved', 'reopened'], $issue->logs()->reorder('id')->pluck('action')->all());
    }

    public function test_small_scope_audit_never_closes_issues_outside_scope(): void
    {
        $a = $this->stocked('IR-SA');
        $b = $this->stocked('IR-SB');
        $this->setBalance($a, 11);
        $this->setBalance($b, 11);
        $this->audit();
        $this->setBalance($a, 10);
        $this->setBalance($b, 10);

        $this->audit('product', 'IR-SB');
        $this->assertSame('RESOLVED', $this->issue('PHANTOM_STOCK', $b)->status);
        $this->assertSame('OPEN', $this->issue('PHANTOM_STOCK', $a)->status, 'phạm vi SP khác không được đóng');

        $this->audit('warehouse', (string) $this->wh2->id);
        $this->assertSame('OPEN', $this->issue('PHANTOM_STOCK', $a)->status, 'phạm vi kho khác không được đóng');

        $this->audit('issue', (string) $this->issue('PHANTOM_STOCK', $a)->id);
        $this->assertSame('RESOLVED', $this->issue('PHANTOM_STOCK', $a)->status);
    }

    public function test_failed_audit_keeps_previous_results(): void
    {
        $a = $this->stocked('IR-F');
        $this->setBalance($a, 12);
        $ok = $this->audit();
        $before = $this->issue('PHANTOM_STOCK', $a)->toArray();

        $this->mock(IntegrityFindingCollector::class, fn ($m) => $m->shouldReceive('collect')->andThrow(new RuntimeException('DB lỗi giả lập')));
        $run = app(InventoryIntegrityAuditor::class)->run('all', null, $this->admin->id);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('DB lỗi giả lập', $run->error);
        $after = $this->issue('PHANTOM_STOCK', $a)->toArray();
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($ok->id, $after['last_seen_run_id']);
        $this->assertSame($ok->id, InventoryAuditRun::lastSuccess()->id);
    }

    public function test_negative_stock_value_counted_once(): void
    {
        $c = Product::create(['code' => 'IR-NEG', 'name' => 'Âm', 'cost_price' => 100000, 'is_active' => true]);
        $this->movement($c->id, $this->wh->id, -5, 100000);
        DB::table('inventory_balances')->insert(['product_id' => $c->id, 'warehouse_id' => $this->wh->id, 'qty_on_hand' => 0,
            'value_on_hand' => 0, 'avg_cost' => 100000, 'initialized_from' => 'stock_entry', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit();

        $neg = $this->issue('NEGATIVE_STOCK', $c);
        $this->assertNotNull($neg);
        $this->assertEquals(-5, (float) $neg->qty_diff);
        $this->assertEquals(500000, (float) $neg->value_diff);
        $this->assertSame('avco_vs_book', $neg->value_basis);
        $this->assertNull($this->issue('PHANTOM_STOCK', $c), 'không lập thêm tồn ảo cho cùng nguyên nhân');
        $this->assertNull($this->issue('VALUE_MISMATCH', $c), 'không lập thêm lệch giá trị cho cùng nguyên nhân');

        $kpis = app(\App\Services\Inventory\Integrity\IntegrityReportQuery::class)->kpis($this->admin);
        $basis = collect($kpis['values'])->firstWhere('basis', 'avco_vs_book');
        $this->assertEquals(500000, $basis['net']);
        $this->assertSame(1, $kpis['groups']['negative']);
        $this->assertSame(0, $kpis['groups']['phantom']);
    }

    public function test_deleted_line_with_snapshot_is_traceable_and_not_flagged(): void
    {
        $p = $this->stocked('IR-SNAP');
        $entryId = DB::table('stock_entries')->insertGetId(['code' => 'NK-IRS', 'warehouse_id' => $this->wh->id, 'status' => 'draft',
            'entry_date' => now()->toDateString(), 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $type = 'App\Models\StockEntry';
        foreach ([90001, 90002] as $itemId) {   // dòng đã bị xóa: 90001 có snapshot, 90002 không
            $this->movement($p->id, $this->wh->id, 1, 100000, ['source_type' => $type, 'source_id' => $entryId, 'source_item_id' => $itemId, 'status' => 'voided']);
            $this->movement($p->id, $this->wh->id, -1, 100000, ['source_type' => $type, 'source_id' => $entryId, 'source_item_id' => $itemId, 'status' => 'voided']);
        }
        activity('stock_entry_item')->withProperties(['stock_entry_item_id' => 90001, 'movement_ids' => [],
            'deleted_item' => ['quantity' => 1, 'unit_price' => 100000]])->log('Xóa dòng hàng #90001 khi sửa phiếu NK-IRS');

        $this->audit();
        $missing = Issue::where('rule_code', 'MISSING_DOCUMENT_LINK')->get();
        $this->assertCount(1, $missing);
        $this->assertStringContainsString('#90002', $missing[0]->cause);
        $this->assertStringNotContainsString('#90001', $missing[0]->cause);

        $trace = $this->getJson(route('reports.inventory_integrity.show', $missing[0]))->assertOk()->json('trace.movements');
        $snap = collect($trace)->firstWhere('source_item_id', 90001);
        $this->assertFalse($snap['item_exists']);
        $this->assertSame(1, $snap['snapshot']['deleted_item']['quantity']);
        $this->assertNull(collect($trace)->firstWhere('source_item_id', 90002)['snapshot']);
        $this->assertNotNull(collect($trace)->first()['link'], 'chứng từ còn tồn tại → có link');
    }

    private function doc(string $table, array $attrs): int
    {
        return DB::table($table)->insertGetId($attrs + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function je(string $refType, int $refId, float $amount, string $code): void
    {
        \App\Models\AccountCode::firstOrCreate(['code' => '1561'], ['name' => '1561', 'type' => 'asset', 'normal_balance' => 'debit', 'is_detail' => true]);
        \App\Models\AccountCode::firstOrCreate(['code' => '3311'], ['name' => '3311', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true]);
        $id = DB::table('journal_entries')->insertGetId(['code' => $code, 'entry_date' => now()->toDateString(), 'description' => $code, 'status' => 'posted',
            'reference_type' => $refType, 'reference_id' => $refId, 'is_auto' => true, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $id, 'account_code' => '1561', 'debit' => max($amount, 0), 'credit' => max(-$amount, 0), 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $id, 'account_code' => '3311', 'debit' => max(-$amount, 0), 'credit' => max($amount, 0), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_transfer_count_return_cancelled_and_deleted_documents_are_checked(): void
    {
        $p = $this->stocked('IR-DOC', 20);
        $u = $this->admin->id;
        // Chuyển kho đã xác nhận 5 cái nhưng chỉ có movement xuất (thiếu movement nhập kho đích)
        $t = $this->doc('stock_transfers', ['code' => 'CK-IR1', 'from_warehouse_id' => $this->wh->id, 'to_warehouse_id' => $this->wh2->id,
            'transfer_date' => now()->toDateString(), 'status' => 'confirmed', 'created_by' => $u]);
        DB::table('stock_transfer_items')->insert(['stock_transfer_id' => $t, 'product_id' => $p->id, 'quantity' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->movement($p->id, $this->wh->id, -5, 100000, ['source_type' => 'App\Models\StockTransfer', 'source_id' => $t]);
        // Phiếu chuyển kho đã hủy nhưng còn movement hiệu lực
        $t2 = $this->doc('stock_transfers', ['code' => 'CK-IR2', 'from_warehouse_id' => $this->wh->id, 'to_warehouse_id' => $this->wh2->id,
            'transfer_date' => now()->toDateString(), 'status' => 'cancelled', 'created_by' => $u]);
        $this->movement($p->id, $this->wh2->id, 2, 100000, ['source_type' => 'App\Models\StockTransfer', 'source_id' => $t2]);
        // Kiểm kê đã xác nhận: movement khớp (−1) nhưng không có JE
        $c = $this->doc('inventory_counts', ['code' => 'IK-IR1', 'warehouse_id' => $this->wh->id, 'count_date' => now()->toDateString(), 'status' => 'confirmed', 'counted_by' => $u]);
        DB::table('inventory_count_items')->insert(['inventory_count_id' => $c, 'product_id' => $p->id, 'system_quantity' => 20, 'counted_quantity' => 19, 'created_at' => now(), 'updated_at' => now()]);
        $this->movement($p->id, $this->wh->id, -1, 100000, ['source_type' => 'App\Models\InventoryCount', 'source_id' => $c]);
        // Phiếu trả hàng NCC đã bị xóa nhưng còn movement hiệu lực
        $this->movement($p->id, $this->wh->id, -1, 100000, ['source_type' => 'App\Models\PurchaseReturn', 'source_id' => 987654]);

        $this->audit();
        $rules = fn (string $doc) => Issue::where('document_ref', 'like', "%{$doc}%")->pluck('rule_code')->all();
        $this->assertContains('QUANTITY_MISMATCH', $rules('CK-IR1'), 'chuyển kho thiếu movement kho đích');
        $this->assertContains('DOCUMENT_STATUS_MISMATCH', $rules('CK-IR2'), 'chuyển kho đã hủy còn movement');
        $this->assertNotContains('QUANTITY_MISMATCH', $rules('IK-IR1'), 'kiểm kê: movement khớp chênh lệch đếm');
        $this->assertContains('MISSING_ACCOUNTING_ENTRY', $rules('IK-IR1'), 'kiểm kê thiếu JE');
        $this->assertContains('DOCUMENT_STATUS_MISMATCH', $rules('PurchaseReturn#987654'), 'chứng từ đã xóa còn movement');
    }

    public function test_voided_movement_still_reflected_in_avco_or_je_is_detected(): void
    {
        $p = $this->stocked('IR-VOID', 10);
        $e = $this->doc('stock_entries', ['code' => 'NK-IRV', 'warehouse_id' => $this->wh->id, 'status' => 'cancelled',
            'entry_date' => now()->toDateString(), 'created_by' => $this->admin->id]);
        // Movement của phiếu đã void nhưng AVCO vẫn giữ (12) và JE vẫn posted, không đảo
        $this->movement($p->id, $this->wh->id, 2, 100000, ['source_type' => 'App\Models\StockEntry', 'source_id' => $e, 'status' => 'voided']);
        $this->setBalance($p, 12);
        $this->je('stock_entry', $e, 200000, 'BT-IRV');

        $this->audit();
        $this->assertNotNull($this->issue('PHANTOM_STOCK', $p), 'AVCO còn tính movement đã void');
        $this->assertSame(1, Issue::where('rule_code', 'INVENTORY_GL_MISMATCH')->where('document_ref', 'NK-IRV')->count(), 'JE chưa đảo theo movement void');
    }

    public function test_value_reconciliation_items_sum_to_each_difference(): void
    {
        $a = $this->stocked('IR-R1');
        $this->setBalance($a, 12);                                        // AVCO +200.000 so với sổ kho
        $this->je('opening_balance', 0, 999000, 'BT-IRR');                 // TK156 không khớp sổ kho
        $run = $this->audit();
        $rec = $run->summary['reconciliation'];

        foreach ($rec['comparisons'] as $c) {
            $this->assertEqualsWithDelta($c['diff'], collect($c['items'])->sum('amount'), 0.011, $c['key'] . ': tổng khoản = chênh');
        }
        [$c1, $c2, $c3] = $rec['comparisons'];
        $this->assertEqualsWithDelta(200000, $c1['diff'], 0.01);
        $this->assertSame('explained', $c1['status']);
        $this->assertEqualsWithDelta($c1['diff'] + $c2['diff'], $c3['diff'], 0.01, 'mục 3 = 1 + 2, không cộng trùng');
        $this->assertSame('unexplained', $c2['status'], 'JE 156 không khớp movement → chưa giải trình, không kết luận khớp');
        $this->assertEquals(['qty' => 0.0005, 'value' => 10], $rec['thresholds']);

        $this->get(route('reports.inventory_integrity.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('lastAllRun.reconciliation.comparisons.0.key', 'avco_vs_book'));
    }
}
