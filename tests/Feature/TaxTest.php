<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseInvoice;
use App\Models\Warehouse;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        
        // Setup permissions
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'accounting.view']);
        $this->user->givePermissionTo('accounting.view');

        $this->actingAs($this->user);

        // Setup company settings
        Setting::set('company_name', 'CÔNG TY TNHH MEGA TEXTILE');
        Setting::set('company_tax_code', '1234567890');
        Setting::set('company_address', 'VSIP Nghệ An');

        // Create a customer
        $this->customer = Customer::create([
            'code' => 'KH-0001',
            'name' => 'Khách Hàng A',
            'tax_code' => '0101010101',
        ]);

        // Create a supplier
        $this->supplier = Supplier::create([
            'code' => 'NCC-0001',
            'name' => 'Nhà Cung Cấp B',
            'tax_code' => '0202020202',
        ]);

        // Create a warehouse
        $this->warehouse = Warehouse::create([
            'code' => 'K01',
            'name' => 'Kho chính',
            'address' => 'Hà Nội',
            'manager_id' => $this->user->id,
            'is_active' => true,
        ]);
        
        // Create a PO (required for Purchase Invoice foreign key constraint)
        $this->po = PurchaseOrder::create([
            'code' => 'MH-0001',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_can_view_tax_summary_and_export_xml_htkk(): void
    {
        // 1. Create a Sales Invoice (VAT đầu ra)
        Invoice::create([
            'code' => 'HĐ-0001',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->toDateString(),
            'subtotal' => 50000000,
            'tax_amount' => 5000000, // 10% VAT
            'total' => 55000000,
            'status' => 'sent',
            'created_by' => $this->user->id,
        ]);

        // 2. Create a Purchase Invoice (VAT đầu vào)
        PurchaseInvoice::create([
            'code' => 'HD-NCC-01',
            'purchase_order_id' => $this->po->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-9999',
            'invoice_date' => now()->toDateString(),
            'supplier_tax_code' => '0202020202',
            'subtotal' => 30000000,
            'tax_amount' => 3000000, // 10% VAT
            'total' => 33000000,
            'status' => 'valid',
            'created_by' => $this->user->id,
        ]);

        // 3. Request Taxes Dashboard
        $response = $this->get(route('accounting.taxes.index', ['period' => now()->format('Y-m')]));
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Accounting/Taxes/Index')
            ->where('summary.total_sales_subtotal', 50000000)
            ->where('summary.total_sales_tax', 5000000)
            ->where('summary.total_purchase_subtotal', 30000000)
            ->where('summary.total_purchase_tax', 3000000)
            ->where('summary.net_tax_payable', 2000000) // 5,000,000 - 3,000,000 = 2,000,000
        );

        // 4. Request HTKK XML export
        $xmlResponse = $this->get(route('accounting.taxes.export-xml', ['period' => now()->format('Y-m')]));
        $xmlResponse->assertStatus(200);
        $xmlResponse->assertHeader('Content-Type', 'application/xml');
        
        $xmlContent = $xmlResponse->getContent();
        
        $this->assertStringContainsString('<MaTKhai>01</MaTKhai>', $xmlContent);
        $this->assertStringContainsString('<NguoiNopThue>CÔNG TY TNHH MEGA TEXTILE</NguoiNopThue>', $xmlContent);
        $this->assertStringContainsString('<MaSoThue>1234567890</MaSoThue>', $xmlContent);
        $this->assertStringContainsString('<ct23>30000000</ct23>', $xmlContent); // purchase subtotal
        $this->assertStringContainsString('<ct24>3000000</ct24>', $xmlContent);   // purchase tax
        $this->assertStringContainsString('<ct32>50000000</ct32>', $xmlContent); // sales subtotal
        $this->assertStringContainsString('<ct33>5000000</ct33>', $xmlContent);   // sales tax
        $this->assertStringContainsString('<ct36>2000000</ct36>', $xmlContent);   // net tax payable
    }
}
