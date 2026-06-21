<?php

namespace Tests\Feature\Purchasing;

use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseInvoiceType;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use App\Models\ProjectWipEntry;
use Tests\TestCase;

class PurchaseInvoiceWipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'admin2@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        $this->seedAccount('3311', 'liability', 'credit', true, '331');
        $this->seedAccount('1331', 'asset', 'debit', true, '133');
        $this->seedAccount('154', 'asset', 'debit', true);

        $this->supplier = Supplier::create([
            'code' => 'NCC-TEST-2',
            'name' => 'NCC2',
            'is_active' => true,
            'payable_account_code' => '3311',
        ]);
    }

    private function seedAccount(string $code, string $type, string $normalBalance, bool $isDetail, ?string $parentCode = null)
    {
        if ($parentCode) {
            \App\Models\AccountCode::firstOrCreate(['code' => $parentCode], ['name' => 'TK ' . $parentCode, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true, 'is_detail' => false]);
        }
        return AccountCode::firstOrCreate(['code' => $code], ['name' => 'TK ' . $code, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true, 'is_detail' => $isDetail]);
    }

    public function test_purchase_invoice_item_with_tk154_creates_wip_and_je_line_with_project(): void
    {
        $customer = \App\Models\Customer::create(['code' => 'CUST-1', 'name' => 'CUST', 'is_active' => true]);
        $project = Project::create(['code' => 'DA-0001', 'name' => 'Test Project', 'customer_id' => $customer->id, 'status' => 'planning', 'created_by' => $this->user->id]);

        $warehouse = \App\Models\Warehouse::create(['name' => 'WH-1', 'is_active' => true]);
        $po = PurchaseOrder::create([
            'code' => 'PO-TEST', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $warehouse->id, 'status' => 'sent', 'order_date' => now(), 'total' => 1000, 'created_by' => $this->user->id,
        ]);

        $pi = PurchaseInvoice::create([
            'code' => 'HD-NCC-0040',
            'purchase_order_id' => $po->id,
            'project_id' => $project->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => 500000,
            'tax_amount' => 40000,
            'total' => 540000,
            'paid_amount' => 0,
            'status' => PurchaseInvoiceStatus::Reviewing,
            'invoice_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $item = PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description' => 'Chi phí thuê xe nâng',
            'account_code' => '154',
            'project_id' => $project->id,
            'amount' => 500000,
            'vat_rate' => 8,
            'tax_amount' => 40000,
            'sort_order' => 1,
        ]);

        // Transition to Valid → triggers posting
        app(\App\Services\PurchaseInvoiceService::class)->transition($pi, PurchaseInvoiceStatus::Valid);

        // JE exists
        $je = JournalEntry::where('reference_type', 'purchase_invoice')->where('reference_id', $pi->id)->first();
        if (is_null($je)) {
            $job = \App\Models\AccountingPostingJob::where('source_type', 'purchase_invoice')->where('source_id', $pi->id)->first();
            $msg = $job ? ($job->error_message ?? 'no error_message') : 'no job found';
            $this->fail('JournalEntry should be created. Posting job error: ' . $msg);
        }

        $je->load('lines');
        $debit154 = $je->lines->where('account_code', '154')->sum('debit');
        $debit1331 = $je->lines->where('account_code', '1331')->sum('debit');
        $credit3311 = $je->lines->where('credit', '>', 0)->sum('credit');

        $this->assertEquals(500000, $debit154);
        $this->assertEquals(40000, $debit1331);
        $this->assertEquals(540000, $credit3311);

        // JE line 154 must have project_id
        $line154 = $je->lines->where('account_code', '154')->first();
        $this->assertEquals($project->id, $line154->project_id);

        // WIP entry created
        $this->assertDatabaseHas('project_wip_entries', [
            'project_id' => $project->id,
            'source_type' => PurchaseInvoice::class,
            'source_id' => $pi->id,
            'source_item_id' => $item->id,
            'amount' => 500000,
        ]);
    }

    public function test_header_project_fallback_creates_je_line_and_wip(): void
    {
        $customer = \App\Models\Customer::create(['code' => 'CUST-2', 'name' => 'CUST2', 'is_active' => true]);
        $project = Project::create(['code' => 'DA-0002', 'name' => 'Proj 2', 'customer_id' => $customer->id, 'status' => 'planning', 'created_by' => $this->user->id]);

        $warehouse = \App\Models\Warehouse::create(['name' => 'WH-2', 'is_active' => true]);

        $po = PurchaseOrder::create([
            'code' => 'PO-TEST-2', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $warehouse->id, 'status' => 'sent', 'order_date' => now(), 'total' => 1000, 'created_by' => $this->user->id,
        ]);

        $pi = PurchaseInvoice::create([
            'code' => 'HD-NCC-0050',
            'purchase_order_id' => $po->id,
            'project_id' => $project->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => 200000,
            'tax_amount' => 20000,
            'total' => 220000,
            'paid_amount' => 0,
            'status' => PurchaseInvoiceStatus::Valid,
            'invoice_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        // Item without project_id -> should fallback to invoice.project_id
        $item = PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description' => 'Chi phí khác',
            'account_code' => '154',
            'project_id' => null,
            'amount' => 200000,
            'vat_rate' => 10,
            'tax_amount' => 20000,
            'sort_order' => 1,
        ]);

        // Do not call transition (simulate legacy valid without JE); call backfill apply
        Artisan::call('projects:backfill-purchase-invoice-wip', ['--apply' => true]);

        $this->assertDatabaseHas('project_wip_entries', [
            'project_id' => $project->id,
            'source_type' => PurchaseInvoice::class,
            'source_id' => $pi->id,
            'source_item_id' => $item->id,
            'amount' => 200000,
        ]);
    }

    public function test_backfill_is_idempotent(): void
    {
        $customer = \App\Models\Customer::create(['code' => 'CUST-3', 'name' => 'CUST3', 'is_active' => true]);
        $project = Project::create(['code' => 'DA-0003', 'name' => 'Proj 3', 'customer_id' => $customer->id, 'status' => 'planning', 'created_by' => $this->user->id]);

        $warehouse = \App\Models\Warehouse::create(['name' => 'WH-3', 'is_active' => true]);

        $po = PurchaseOrder::create([
            'code' => 'PO-TEST-3', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $warehouse->id, 'status' => 'sent', 'order_date' => now(), 'total' => 1000, 'created_by' => $this->user->id,
        ]);

        $pi = PurchaseInvoice::create([
            'code' => 'HD-NCC-0060',
            'purchase_order_id' => $po->id,
            'project_id' => $project->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => 300000,
            'tax_amount' => 30000,
            'total' => 330000,
            'paid_amount' => 0,
            'status' => PurchaseInvoiceStatus::Valid,
            'invoice_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $item = PurchaseInvoiceItem::create([
            'purchase_invoice_id' => $pi->id,
            'description' => 'Chi phí 3',
            'account_code' => '154',
            'project_id' => $project->id,
            'amount' => 300000,
            'vat_rate' => 10,
            'tax_amount' => 30000,
            'sort_order' => 1,
        ]);

        // Ensure no WIP initially
        $this->assertDatabaseMissing('project_wip_entries', ['source_type' => PurchaseInvoice::class, 'source_id' => $pi->id, 'source_item_id' => $item->id]);

        Artisan::call('projects:backfill-purchase-invoice-wip', ['--apply' => true]);
        $count1 = ProjectWipEntry::where('source_type', PurchaseInvoice::class)->where('source_id', $pi->id)->where('source_item_id', $item->id)->count();
        $this->assertEquals(1, $count1);

        // Run again: should remain 1
        Artisan::call('projects:backfill-purchase-invoice-wip', ['--apply' => true]);
        $count2 = ProjectWipEntry::where('source_type', PurchaseInvoice::class)->where('source_id', $pi->id)->where('source_item_id', $item->id)->count();
        $this->assertEquals(1, $count2);
    }
}
