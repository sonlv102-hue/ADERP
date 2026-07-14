<?php

namespace Tests\Feature\Projects;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectDirectMaterial;
use App\Models\ProjectWipEntry;
use App\Models\PurchaseOrder;
use App\Models\StockEntry;
use App\Models\StockExit;
use App\Models\StockExitItem;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * TC1: Phiếu xuất kho dự án confirmed → hiển thị ở stockExitItems
 * TC2: Tab "Vật tư đã xuất" không nhận thêm từ route materials.store
 * TC3: Direct material loại tracking_only — không tạo JE, không tạo WIP entry
 * TC4: Direct material loại invoice_link — không tạo JE, lưu purchase_invoice_item_id
 * TC5: Direct material loại journal_entry — tạo JE + WIP entry
 * TC6: Hủy direct material đã có JE — JE bị reverse
 * TC7: Tổng hợp stockExitTotal + directMaterialTotal không bị nhân đôi
 * TC8: journal_entry + payment_method=cash, không VAT — JE Nợ154/Có1111, không tạo stock_movement
 * TC9: journal_entry + payment_method=payable, có VAT — JE Nợ154/Nợ1331/Có3311, WIP = phần trước VAT
 * TC10: post_immediately=false → lưu nháp không JE/WIP; gọi route post → ghi nhận JE+WIP
 */
class ProjectDirectMaterialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Warehouse $warehouse;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u, $a) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $customer       = Customer::create(['code' => 'KH-T01', 'name' => 'KH Test', 'phone' => '0900000001']);
        $this->warehouse = Warehouse::create(['name' => 'Kho Test', 'code' => 'KT']);
        $this->product   = Product::create(['code' => 'SP-T01', 'name' => 'Sản phẩm test', 'unit' => 'cái', 'cost_price' => 100000, 'is_active' => true]);

        $this->project = Project::create([
            'code'        => 'DA-TEST',
            'name'        => 'Dự án test',
            'status'      => 'in_progress',
            'customer_id' => $customer->id,
            'created_by'  => $this->user->id,
        ]);

        // Seed tài khoản kế toán cần thiết
        foreach (['154', '1561', '1331', '3311', '331', '6321', '5111', '131', '1311', '1111', '1121', '141'] as $code) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => "TK {$code}", 'type' => 'asset', 'normal_balance' => 'debit',
                'level' => 2, 'is_detail' => true, 'is_active' => true,
            ]);
        }
    }

    // ─── TC1: Phiếu xuất kho → hiển thị ở tab stockExitItems ────────────────

    public function test_tc1_confirmed_stock_exit_appears_in_stock_exit_items(): void
    {
        $exit = StockExit::create([
            'code'          => 'XK-TEST-DA',
            'warehouse_id'  => $this->warehouse->id,
            'project_id'    => $this->project->id,
            'issue_purpose' => 'project_cost',
            'exit_date'     => '2026-06-15',
            'status'        => 'confirmed',
            'created_by'    => $this->user->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id,
            'product_id'    => $this->product->id,
            'quantity'      => 5,
            'unit_price'    => 0,
            'source_cost'   => 100000,
            'total_cost'    => 500000,
        ]);

        $res = $this->get(route('projects.projects.show', $this->project->id));
        $res->assertStatus(200);

        $stockExitItems = $res->original->getData()['page']['props']['stockExitItems'];
        $this->assertNotEmpty($stockExitItems, 'stockExitItems phải có dữ liệu');
        $this->assertEquals('XK-TEST-DA', $stockExitItems[0]['exit_code']);
        $this->assertEquals(5, $stockExitItems[0]['quantity']);
        $this->assertEquals(500000, $stockExitItems[0]['total_cost']);
    }

    // ─── TC2: materials.store route vẫn hoạt động (không bị xóa), tab mới độc lập

    public function test_tc2_materials_store_route_still_exists(): void
    {
        $res = $this->post(route('projects.projects.materials.store', $this->project->id), [
            'product_id' => $this->product->id,
            'quantity'   => 3,
            'unit_price' => 50000,
        ]);
        // Phải redirect (không 404, không 405)
        $res->assertRedirect();
        $this->assertDatabaseHas('project_materials', ['project_id' => $this->project->id, 'quantity' => 3]);
    }

    // ─── TC3: Direct material tracking_only — không tạo JE ────────────────────

    public function test_tc3_tracking_only_creates_no_journal_entry(): void
    {
        $res = $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'    => 'Vật tư phát sinh test',
            'quantity'        => 2,
            'unit_price'      => 80000,
            'occurrence_date' => '2026-06-15',
            'handling_type'   => 'tracking_only',
            'notes'           => 'Thử nghiệm',
        ]);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $this->assertNotNull($mat);
        $this->assertEquals('tracking_only', $mat->handling_type->value);
        $this->assertNull($mat->journal_entry_id, 'tracking_only không được tạo JE');
        $this->assertEquals('active', $mat->status);

        $wipCount = ProjectWipEntry::where('project_id', $this->project->id)
            ->where('source_type', ProjectDirectMaterial::class)
            ->count();
        $this->assertEquals(0, $wipCount, 'tracking_only không tạo WIP entry');
    }

    // ─── TC4: Direct material invoice_link — không tạo JE ─────────────────────

    public function test_tc4_invoice_link_creates_no_journal_entry(): void
    {
        $res = $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'    => 'Vật tư phát sinh HĐ',
            'quantity'        => 1,
            'unit_price'      => 150000,
            'occurrence_date' => '2026-06-15',
            'handling_type'   => 'invoice_link',
            'notes'           => 'Liên kết HĐ 123',
        ]);

        $res->assertSessionHasNoErrors();

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $this->assertNotNull($mat);
        $this->assertEquals('invoice_link', $mat->handling_type->value);
        $this->assertNull($mat->journal_entry_id, 'invoice_link không tạo JE mới');
        $this->assertEquals('active', $mat->status);
    }

    // ─── TC5: Direct material journal_entry — tạo JE + WIP entry ──────────────

    public function test_tc5_journal_entry_creates_je_and_wip_entry(): void
    {
        $res = $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'        => 'Vật tư phát sinh TK154',
            'quantity'            => 3,
            'unit_price'          => 200000,
            'occurrence_date'     => '2026-06-15',
            'handling_type'       => 'journal_entry',
            'credit_account_code' => '3311',
            'notes'               => 'Chi phí mua vật liệu',
        ]);

        $res->assertSessionHasNoErrors();

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $this->assertNotNull($mat);
        $this->assertEquals('journal_entry', $mat->handling_type->value);
        $this->assertNotNull($mat->journal_entry_id, 'journal_entry phải tạo JE');
        $this->assertEquals(600000, (float) $mat->total_amount);
        $this->assertEquals('active', $mat->status);

        $je = $mat->journalEntry;
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status, 'JE phải posted ngay');

        $lines = $je->lines;
        $debitLine  = $lines->firstWhere('debit', '>', 0);
        $creditLine = $lines->firstWhere('credit', '>', 0);
        $this->assertEquals('154', $debitLine->account_code);
        $this->assertEquals('3311', $creditLine->account_code);
        $this->assertEquals(600000, (float) $debitLine->debit);

        $wip = ProjectWipEntry::where('project_id', $this->project->id)
            ->where('source_type', ProjectDirectMaterial::class)
            ->first();
        $this->assertNotNull($wip, 'WIP entry phải được tạo cho type journal_entry');
        $this->assertEquals(600000, (float) $wip->amount);
    }

    // ─── TC6: Hủy direct material đã có JE — JE bị reverse ───────────────────

    public function test_tc6_cancel_with_journal_entry_creates_reversal(): void
    {
        // Tạo trước
        $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'        => 'VT hủy test',
            'quantity'            => 1,
            'unit_price'          => 300000,
            'occurrence_date'     => '2026-06-15',
            'handling_type'       => 'journal_entry',
            'credit_account_code' => '3311',
        ]);

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $jeId = $mat->journal_entry_id;
        $this->assertNotNull($jeId);

        // Hủy
        $res = $this->delete(route('projects.projects.direct-materials.destroy', [$this->project->id, $mat->id]), [
            'cancel_reason' => 'Test hủy',
        ]);
        $res->assertSessionHasNoErrors();

        $mat->refresh();
        $this->assertEquals('cancelled', $mat->status);
        $this->assertEquals('Test hủy', $mat->cancel_reason);

        // JE gốc phải bị reversed
        $originalJe = \App\Models\JournalEntry::find($jeId);
        $this->assertEquals('reversed', $originalJe->status, 'JE gốc phải được đảo thành reversed');
    }

    // ─── TC7: stockExitTotal + directMaterialTotal — không bị nhân đôi ─────────

    public function test_tc7_totals_not_double_counted(): void
    {
        // Tạo stock exit (50 * 100000 = 5,000,000)
        $exit = StockExit::create([
            'code' => 'XK-TOTAL-TEST', 'warehouse_id' => $this->warehouse->id,
            'project_id' => $this->project->id, 'issue_purpose' => 'project_cost',
            'exit_date' => '2026-06-15', 'status' => 'confirmed', 'created_by' => $this->user->id,
        ]);
        StockExitItem::create([
            'stock_exit_id' => $exit->id, 'product_id' => $this->product->id,
            'quantity' => 50, 'unit_price' => 0, 'source_cost' => 100000, 'total_cost' => 5000000,
        ]);

        // Tạo direct material tracking_only (2 * 80000 = 160,000)
        $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name' => 'VT tracking', 'quantity' => 2, 'unit_price' => 80000,
            'occurrence_date' => '2026-06-15', 'handling_type' => 'tracking_only',
        ]);

        $res = $this->get(route('projects.projects.show', $this->project->id));
        $data = $res->original->getData()['page']['props'];

        $this->assertEquals(5000000, $data['stockExitTotal'], 'stockExitTotal phải = 5,000,000');
        $this->assertEquals(160000, $data['directMaterialTotal'], 'directMaterialTotal phải = 160,000');

        // Hai giá trị không trùng nhau
        $this->assertNotEquals($data['stockExitTotal'], $data['directMaterialTotal']);
    }

    // ─── TC8: payment_method=cash, không VAT — JE Nợ154/Có1111, không tạo stock_movement ───

    public function test_tc8_cash_payment_method_no_vat(): void
    {
        $res = $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'    => 'VT chi tiền mặt',
            'quantity'        => 1,
            'unit_price'      => 250000,
            'occurrence_date' => '2026-06-15',
            'handling_type'   => 'journal_entry',
            'payment_method'  => 'cash',
        ]);
        $res->assertSessionHasNoErrors();

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $this->assertEquals('cash', $mat->payment_method);
        $this->assertEquals('1111', $mat->credit_account_code);
        $this->assertEquals('active', $mat->status);

        $je = $mat->journalEntry;
        $this->assertNotNull($je);
        $lines = $je->lines;
        $this->assertEquals('154', $lines->firstWhere('debit', '>', 0)->account_code);
        $this->assertEquals('1111', $lines->firstWhere('credit', '>', 0)->account_code);
        $this->assertEquals(250000, (float) $lines->firstWhere('debit', '>', 0)->debit);

        $wip = ProjectWipEntry::where('source_type', ProjectDirectMaterial::class)->where('source_id', $mat->id)->first();
        $this->assertNotNull($wip);
        $this->assertEquals(250000, (float) $wip->amount);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    // ─── TC9: payment_method=payable, có VAT — JE Nợ154/Nợ1331/Có3311, WIP = phần trước VAT ───

    public function test_tc9_payable_with_vat(): void
    {
        $res = $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'    => 'VT công nợ NCC có VAT',
            'quantity'        => 1,
            'unit_price'      => 1000000,
            'vat_rate'        => 10,
            'vat_amount'      => 100000,
            'occurrence_date' => '2026-06-15',
            'handling_type'   => 'journal_entry',
            'payment_method'  => 'payable',
        ]);
        $res->assertSessionHasNoErrors();

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $this->assertEquals(100000, (float) $mat->vat_amount);

        $je = $mat->journalEntry;
        $lines = $je->lines;
        $debit154 = $lines->firstWhere('account_code', '154');
        $debit1331 = $lines->firstWhere('account_code', '1331');
        $credit3311 = $lines->firstWhere('account_code', '3311');

        $this->assertEquals(1000000, (float) $debit154->debit);
        $this->assertEquals(100000, (float) $debit1331->debit);
        $this->assertEquals(1100000, (float) $credit3311->credit);

        $wip = ProjectWipEntry::where('source_type', ProjectDirectMaterial::class)->where('source_id', $mat->id)->first();
        $this->assertEquals(1000000, (float) $wip->amount, 'WIP phải là phần trước VAT');
    }

    // ─── TC10: post_immediately=false → nháp; gọi route post → ghi nhận ───────

    public function test_tc10_draft_then_post(): void
    {
        $res = $this->post(route('projects.projects.direct-materials.store', $this->project->id), [
            'product_name'      => 'VT nháp',
            'quantity'           => 1,
            'unit_price'         => 400000,
            'occurrence_date'    => '2026-06-15',
            'handling_type'      => 'journal_entry',
            'payment_method'     => 'bank',
            'post_immediately'   => false,
        ]);
        $res->assertSessionHasNoErrors();

        $mat = ProjectDirectMaterial::where('project_id', $this->project->id)->first();
        $this->assertEquals('draft', $mat->status);
        $this->assertNull($mat->journal_entry_id);
        $this->assertEquals(0, ProjectWipEntry::where('source_type', ProjectDirectMaterial::class)->where('source_id', $mat->id)->count());

        $res2 = $this->post(route('projects.projects.direct-materials.post', [$this->project->id, $mat->id]));
        $res2->assertSessionHasNoErrors();

        $mat->refresh();
        $this->assertEquals('active', $mat->status);
        $this->assertNotNull($mat->journal_entry_id);

        $wip = ProjectWipEntry::where('source_type', ProjectDirectMaterial::class)->where('source_id', $mat->id)->first();
        $this->assertNotNull($wip);
        $this->assertEquals(400000, (float) $wip->amount);
    }
}
