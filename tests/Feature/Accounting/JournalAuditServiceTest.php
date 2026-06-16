<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\JournalAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kiểm tra JournalAuditService — 7 checks E001–E007.
 */
class JournalAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private JournalAuditService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(JournalAuditService::class);
        $this->user    = User::factory()->create();

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function seedAccount(string $code, bool $isDetail, string $type = 'asset', string $normal = 'debit'): AccountCode
    {
        return AccountCode::firstOrCreate(['code' => $code], [
            'name'           => 'TK ' . $code,
            'type'           => $type,
            'normal_balance' => $normal,
            'is_detail'      => $isDetail,
            'is_active'      => true,
        ]);
    }

    /** Tạo JournalEntry posted với 2 dòng cân bằng */
    private function makePostedJE(string $refType, int $refId, string $code, string $date = '2026-06-01'): JournalEntry
    {
        $je = JournalEntry::create([
            'code'           => $code,
            'entry_date'     => $date,
            'description'    => "Test JE {$code}",
            'reference_type' => $refType,
            'reference_id'   => $refId,
            'status'         => 'posted',
            'is_auto'        => true,
            'created_by'     => $this->user->id,
        ]);

        $this->seedAccount('1311', true);
        $this->seedAccount('5111', true, 'revenue', 'credit');

        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '1311', 'debit' => 1000000, 'credit' => 0,       'description' => 'Dr'],
            ['journal_entry_id' => $je->id, 'account_code' => '5111', 'debit' => 0,       'credit' => 1000000, 'description' => 'Cr'],
        ]);

        return $je;
    }

    private function makePurchaseOrder(Supplier $supplier): int
    {
        $warehouse = Warehouse::firstOrCreate(['name' => 'Kho PO'], ['is_active' => true]);
        return DB::table('purchase_orders')->insertGetId([
            'code'         => 'MH-' . uniqid(),
            'supplier_id'  => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_by'   => $this->user->id,
            'order_date'   => '2026-06-01',
            'status'       => 'confirmed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function filters(): array
    {
        return ['from' => '2026-01-01', 'to' => '2026-12-31'];
    }

    private function findingsFor(string $errorCode): array
    {
        return array_filter(
            $this->service->run($this->filters()),
            fn($f) => $f['error_code'] === $errorCode
        );
    }

    // ─── E001: Thiếu bút toán ────────────────────────────────────────────────

    public function test_e001_detects_sales_invoice_missing_je(): void
    {
        $customer = Customer::create(['code' => 'KH-E001A', 'name' => 'KH A', 'is_active' => true]);
        DB::table('invoices')->insert([
            'code'        => 'HD-0001',
            'customer_id' => $customer->id,
            'issue_date'  => '2026-06-01',
            'due_date'    => '2026-07-01',
            'subtotal'    => 1000000,
            'tax_amount'  => 100000,
            'total'       => 1100000,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $findings = $this->findingsFor('E001');

        $this->assertCount(1, $findings);
        $first = array_values($findings)[0];
        $this->assertSame('invoice', $first['document_type']);
        $this->assertSame('HD-0001', $first['document_code']);
    }

    public function test_e001_ignores_sales_invoice_with_posted_je(): void
    {
        $customer = Customer::create(['code' => 'KH-E001B', 'name' => 'KH B', 'is_active' => true]);
        DB::table('invoices')->insert([
            'code'        => 'HD-0002',
            'customer_id' => $customer->id,
            'issue_date'  => '2026-06-01',
            'due_date'    => '2026-07-01',
            'subtotal'    => 1000000,
            'tax_amount'  => 100000,
            'total'       => 1100000,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        $invoiceId = DB::table('invoices')->where('code', 'HD-0002')->value('id');
        $this->makePostedJE('invoice', $invoiceId, 'BT-0001');

        $findings = $this->findingsFor('E001');
        $this->assertCount(0, $findings);
    }

    public function test_e001_detects_service_purchase_invoice_missing_je(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-0001', 'name' => 'Test Supplier', 'is_active' => true]);
        $poId     = $this->makePurchaseOrder($supplier);

        DB::table('purchase_invoices')->insert([
            'code'              => 'HD-NCC-0001',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $poId,
            'invoice_date'      => '2026-06-05',
            'due_date'          => '2026-07-05',
            'subtotal'          => 500000,
            'tax_amount'        => 50000,
            'total'             => 550000,
            'status'            => 'valid',
            'invoice_type'      => 'management_expense',
            'created_by'        => $this->user->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $findings = $this->findingsFor('E001');
        $this->assertCount(1, $findings);
        $first = array_values($findings)[0];
        $this->assertSame('purchase_invoice', $first['document_type']);
        $this->assertSame('critical', $first['severity']);
    }

    public function test_e001_ignores_inventory_backed_purchase_invoice(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-0002', 'name' => 'Supplier B', 'is_active' => true]);
        $poId     = $this->makePurchaseOrder($supplier);

        DB::table('purchase_invoices')->insert([
            'code'              => 'HD-NCC-0002',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $poId,
            'invoice_date'      => '2026-06-05',
            'due_date'          => '2026-07-05',
            'subtotal'          => 500000,
            'tax_amount'        => 50000,
            'total'             => 550000,
            'status'            => 'valid',
            'invoice_type'      => 'resale_goods', // Inventory-backed — JE sinh qua StockService
            'created_by'        => $this->user->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $findings = $this->findingsFor('E001');
        $this->assertCount(0, $findings);
    }

    public function test_e001_detects_confirmed_stock_entry_missing_je(): void
    {
        $warehouse = Warehouse::create(['name' => 'Kho A', 'is_active' => true]);

        DB::table('stock_entries')->insert([
            'code'         => 'NK-0001',
            'warehouse_id' => $warehouse->id,
            'created_by'   => $this->user->id,
            'entry_date'   => '2026-06-10',
            'status'       => 'confirmed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $findings = $this->findingsFor('E001');
        $this->assertCount(1, $findings);
        $first = array_values($findings)[0];
        $this->assertSame('stock_entry', $first['document_type']);
    }

    // ─── E002: Bút toán mất cân bằng ────────────────────────────────────────

    public function test_e002_detects_imbalanced_je(): void
    {
        $this->seedAccount('1311', true);
        $this->seedAccount('3311', true, 'liability', 'credit');

        $je = JournalEntry::create([
            'code'       => 'BT-IMBAL',
            'entry_date' => '2026-06-01',
            'description'=> 'Imbalanced',
            'status'     => 'posted',
            'is_auto'    => false,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '1311', 'debit' => 1000000, 'credit' => 0,      'description' => 'Dr'],
            ['journal_entry_id' => $je->id, 'account_code' => '3311', 'debit' => 0,       'credit' => 800000, 'description' => 'Cr'],
        ]);

        $findings = $this->findingsFor('E002');
        $this->assertCount(1, $findings);
        $this->assertSame('journal_entry', array_values($findings)[0]['document_type']);
    }

    public function test_e002_ignores_balanced_je(): void
    {
        $this->seedAccount('1311', true);
        $this->seedAccount('3311', true, 'liability', 'credit');

        $je = JournalEntry::create([
            'code'       => 'BT-BAL',
            'entry_date' => '2026-06-01',
            'description'=> 'Balanced',
            'status'     => 'posted',
            'is_auto'    => false,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '1311', 'debit' => 1000000, 'credit' => 0,       'description' => 'Dr'],
            ['journal_entry_id' => $je->id, 'account_code' => '3311', 'debit' => 0,       'credit' => 1000000, 'description' => 'Cr'],
        ]);

        $this->assertCount(0, $this->findingsFor('E002'));
    }

    // ─── E003: Hạch toán vào TK tổng hợp ────────────────────────────────────

    public function test_e003_detects_parent_account_usage(): void
    {
        $this->seedAccount('133', false); // TK tổng hợp (is_detail=false)
        $this->seedAccount('3311', true, 'liability', 'credit');

        $je = JournalEntry::create([
            'code'       => 'BT-PARENT',
            'entry_date' => '2026-06-01',
            'description'=> 'Parent account',
            'status'     => 'posted',
            'is_auto'    => false,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '133',  'debit' => 1000000, 'credit' => 0,       'description' => 'Dr TK tổng hợp'],
            ['journal_entry_id' => $je->id, 'account_code' => '3311', 'debit' => 0,       'credit' => 1000000, 'description' => 'Cr'],
        ]);

        $findings = $this->findingsFor('E003');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('133', array_values($findings)[0]['description']);
    }

    public function test_e003_ignores_detail_accounts(): void
    {
        $this->seedAccount('1331', true);
        $this->seedAccount('3311', true, 'liability', 'credit');

        $je = JournalEntry::create([
            'code'       => 'BT-DETAIL',
            'entry_date' => '2026-06-01',
            'description'=> 'Detail accounts only',
            'status'     => 'posted',
            'is_auto'    => false,
            'created_by' => $this->user->id,
        ]);

        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '1331', 'debit' => 1000000, 'credit' => 0,       'description' => 'Dr'],
            ['journal_entry_id' => $je->id, 'account_code' => '3311', 'debit' => 0,       'credit' => 1000000, 'description' => 'Cr'],
        ]);

        $this->assertCount(0, $this->findingsFor('E003'));
    }

    // ─── E004: HĐ mua hàng hóa nhưng hạch toán vào 64x ─────────────────────

    public function test_e004_detects_goods_invoice_with_expense_account(): void
    {
        $this->seedAccount('6421', true, 'expense', 'debit');
        $this->seedAccount('3311', true, 'liability', 'credit');

        $supplier = Supplier::create(['code' => 'NCC-0003', 'name' => 'Supplier C', 'is_active' => true]);
        $poId     = $this->makePurchaseOrder($supplier);

        $piId = DB::table('purchase_invoices')->insertGetId([
            'code'              => 'HD-NCC-0003',
            'supplier_id'       => $supplier->id,
            'purchase_order_id' => $poId,
            'invoice_date'      => '2026-06-05',
            'due_date'          => '2026-07-05',
            'subtotal'          => 2000000,
            'tax_amount'        => 200000,
            'total'             => 2200000,
            'status'            => 'valid',
            'invoice_type'      => 'resale_goods', // Hàng hóa — phải hạch toán vào 1561, không phải 6421
            'created_by'        => $this->user->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $je = JournalEntry::create([
            'code'           => 'BT-E004',
            'entry_date'     => '2026-06-05',
            'description'    => 'Nhập hàng hóa sai TK',
            'reference_type' => 'purchase_invoice',
            'reference_id'   => $piId,
            'status'         => 'posted',
            'is_auto'        => true,
            'created_by'     => $this->user->id,
        ]);

        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '6421', 'debit' => 2000000, 'credit' => 0,       'description' => 'Sai'],
            ['journal_entry_id' => $je->id, 'account_code' => '3311', 'debit' => 0,       'credit' => 2000000, 'description' => 'Cr'],
        ]);

        $findings = $this->findingsFor('E004');
        $this->assertCount(1, $findings);
        $this->assertSame('purchase_invoice', array_values($findings)[0]['document_type']);
    }

    // ─── E005: Bút toán trùng ────────────────────────────────────────────────

    public function test_e005_detects_duplicate_je_for_same_document(): void
    {
        $this->seedAccount('1311', true);
        $this->seedAccount('5111', true, 'revenue', 'credit');

        $piId = 999;

        foreach (['BT-DUP-1', 'BT-DUP-2'] as $code) {
            $je = JournalEntry::create([
                'code'           => $code,
                'entry_date'     => '2026-06-01',
                'description'    => 'Duplicate',
                'reference_type' => 'purchase_invoice',
                'reference_id'   => $piId,
                'status'         => 'posted',
                'is_auto'        => true,
                'created_by'     => $this->user->id,
            ]);
            JournalEntryLine::insert([
                ['journal_entry_id' => $je->id, 'account_code' => '1311', 'debit' => 500000, 'credit' => 0,      'description' => 'Dr'],
                ['journal_entry_id' => $je->id, 'account_code' => '5111', 'debit' => 0,      'credit' => 500000, 'description' => 'Cr'],
            ]);
        }

        $findings = $this->findingsFor('E005');
        $this->assertCount(1, $findings);
        $this->assertSame('purchase_invoice', array_values($findings)[0]['document_type']);
        $this->assertSame(999, array_values($findings)[0]['document_id']);
    }

    public function test_e005_ignores_single_je_per_document(): void
    {
        $this->seedAccount('1311', true);
        $this->seedAccount('5111', true, 'revenue', 'credit');

        $je = JournalEntry::create([
            'code'           => 'BT-SINGLE',
            'entry_date'     => '2026-06-01',
            'description'    => 'Single',
            'reference_type' => 'invoice',
            'reference_id'   => 1,
            'status'         => 'posted',
            'is_auto'        => true,
            'created_by'     => $this->user->id,
        ]);
        JournalEntryLine::insert([
            ['journal_entry_id' => $je->id, 'account_code' => '1311', 'debit' => 1000000, 'credit' => 0,       'description' => 'Dr'],
            ['journal_entry_id' => $je->id, 'account_code' => '5111', 'debit' => 0,       'credit' => 1000000, 'description' => 'Cr'],
        ]);

        $this->assertCount(0, $this->findingsFor('E005'));
    }

    // ─── E006: Chứng từ hủy nhưng JE vẫn posted ─────────────────────────────

    public function test_e006_detects_cancelled_invoice_with_posted_je(): void
    {
        $customer = Customer::create(['code' => 'KH-E006A', 'name' => 'KH E006A', 'is_active' => true]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'code'        => 'HD-CANCEL',
            'customer_id' => $customer->id,
            'issue_date'  => '2026-06-01',
            'due_date'    => '2026-07-01',
            'subtotal'    => 1000000,
            'tax_amount'  => 100000,
            'total'       => 1100000,
            'status'      => 'cancelled',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->makePostedJE('invoice', $invoiceId, 'BT-CANCEL');

        $findings = $this->findingsFor('E006');
        $this->assertCount(1, $findings);
        $first = array_values($findings)[0];
        $this->assertSame('invoice', $first['document_type']);
        $this->assertSame('critical', $first['severity']);
    }

    public function test_e006_ignores_cancelled_invoice_without_je(): void
    {
        $customer = Customer::create(['code' => 'KH-E006B', 'name' => 'KH E006B', 'is_active' => true]);
        DB::table('invoices')->insert([
            'code'        => 'HD-CANCEL-NOJE',
            'customer_id' => $customer->id,
            'issue_date'  => '2026-06-01',
            'due_date'    => '2026-07-01',
            'subtotal'    => 1000000,
            'tax_amount'  => 100000,
            'total'       => 1100000,
            'status'      => 'cancelled',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->assertCount(0, $this->findingsFor('E006'));
    }

    // ─── E007: Thiếu COGS (không có phiếu xuất kho xác nhận) ────────────────

    public function test_e007_detects_invoice_without_stock_exit(): void
    {
        $customer = Customer::create(['code' => 'KH-0001', 'name' => 'KH Test', 'is_active' => true]);
        $product  = DB::table('products')->insertGetId([
            'code'      => 'SP-0001',
            'name'      => 'Sản phẩm test',
            'item_type' => 'goods',
            'unit'      => 'cái',
            'is_active' => true,
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'code'        => 'DH-0001',
            'customer_id' => $customer->id,
            'order_date'  => '2026-06-01',
            'status'      => 'confirmed',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id'   => $orderId,
            'product_id' => $product,
            'name'       => 'Sản phẩm test',
            'unit'       => 'cái',
            'quantity'   => 2,
            'unit_price' => 500000,
            'vat_rate'   => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'code'        => 'HD-NOCOGS',
            'customer_id' => $customer->id,
            'order_id'    => $orderId,
            'issue_date'  => '2026-06-05',
            'due_date'    => '2026-07-05',
            'subtotal'    => 1000000,
            'tax_amount'  => 100000,
            'total'       => 1100000,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $findings = $this->findingsFor('E007');
        $this->assertCount(1, $findings);
        $this->assertSame('invoice', array_values($findings)[0]['document_type']);
    }

    public function test_e007_ignores_invoice_with_confirmed_stock_exit(): void
    {
        $customer  = Customer::create(['code' => 'KH-0002', 'name' => 'KH Test 2', 'is_active' => true]);
        $warehouse = Warehouse::create(['name' => 'Kho B', 'is_active' => true]);
        $product   = DB::table('products')->insertGetId([
            'code'      => 'SP-0002',
            'name'      => 'Sản phẩm B',
            'item_type' => 'goods',
            'unit'      => 'cái',
            'is_active' => true,
            'created_at'=> now(),
            'updated_at'=> now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'code'        => 'DH-0002',
            'customer_id' => $customer->id,
            'order_date'  => '2026-06-01',
            'status'      => 'confirmed',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id'   => $orderId,
            'product_id' => $product,
            'name'       => 'Sản phẩm B',
            'unit'       => 'cái',
            'quantity'   => 1,
            'unit_price' => 1000000,
            'vat_rate'   => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'code'        => 'HD-WITHCOGS',
            'customer_id' => $customer->id,
            'order_id'    => $orderId,
            'issue_date'  => '2026-06-05',
            'due_date'    => '2026-07-05',
            'subtotal'    => 1000000,
            'tax_amount'  => 100000,
            'total'       => 1100000,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Có phiếu xuất kho confirmed → không phải E007
        DB::table('stock_exits')->insert([
            'code'         => 'XK-0001',
            'warehouse_id' => $warehouse->id,
            'customer_id'  => $customer->id,
            'order_id'     => $orderId,
            'created_by'   => $this->user->id,
            'exit_date'    => '2026-06-05',
            'status'       => 'confirmed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->assertCount(0, $this->findingsFor('E007'));
    }

    // ─── Date filter ─────────────────────────────────────────────────────────

    public function test_date_filter_excludes_out_of_range_documents(): void
    {
        $customer = Customer::create(['code' => 'KH-DATE', 'name' => 'KH Date', 'is_active' => true]);
        // Invoice ngoài range (2025)
        DB::table('invoices')->insert([
            'code'        => 'HD-OLD',
            'customer_id' => $customer->id,
            'issue_date'  => '2025-01-15',
            'due_date'    => '2025-02-15',
            'subtotal'    => 500000,
            'tax_amount'  => 50000,
            'total'       => 550000,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $findings = array_filter(
            $this->service->run(['from' => '2026-01-01', 'to' => '2026-12-31']),
            fn($f) => $f['error_code'] === 'E001'
        );

        $this->assertCount(0, $findings);
    }
}
