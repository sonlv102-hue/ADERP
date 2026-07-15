<?php

namespace Tests\Feature;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TC1: HĐ mua valid/paid không có JE → xuất hiện trong journalAuditFindings trên Dashboard.
 * TC2: HĐ mua status=pending (chưa duyệt, ngoài phạm vi check E001) → không xuất hiện.
 */
class DashboardJournalAuditFindingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->supplier = Supplier::create([
            'code' => 'NCC-DASH', 'name' => 'NCC Dashboard Test', 'is_active' => true,
        ]);
        $warehouse = Warehouse::create(['name' => 'Kho Dashboard', 'code' => 'DASH', 'address' => 'HN']);
        $this->po = PurchaseOrder::create([
            'code' => 'MH-DASH-' . uniqid(), 'supplier_id' => $this->supplier->id,
            'warehouse_id' => $warehouse->id, 'status' => 'sent',
            'order_date' => now()->toDateString(), 'total' => 11_000_000, 'created_by' => $this->user->id,
        ]);
    }

    private function makePi(?string $code = null): PurchaseInvoice
    {
        return PurchaseInvoice::create([
            'code'              => $code ?? 'HD-NCC-DASH-' . uniqid(),
            'purchase_order_id' => $this->po->id,
            'supplier_id'       => $this->supplier->id,
            'subtotal'          => 10_000_000,
            'tax_amount'        => 1_000_000,
            'total'             => 11_000_000,
            'paid_amount'       => 0,
            'status'            => PurchaseInvoiceStatus::Valid,
            'invoice_type'      => null,
            'invoice_date'      => now()->toDateString(),
            'created_by'        => $this->user->id,
        ]);
    }

    public function test_tc1_missing_je_invoice_appears_in_dashboard_journal_audit_findings(): void
    {
        $pi = $this->makePi('HD-NCC-DASHTC1');

        $this->actingAs($this->user);
        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $response->assertInertia(function ($page) use ($pi) {
            $findings = $page->toArray()['props']['journalAuditFindings'];
            $match = collect($findings)->firstWhere('document_code', $pi->code);

            $this->assertNotNull($match, 'HĐ thiếu JE phải xuất hiện trong journalAuditFindings.');
            $this->assertEquals('E001', $match['error_code']);
            $this->assertEquals((float) $pi->total, $match['document_amount']);
            $this->assertEquals($this->supplier->name, $match['partner_name']);

            return true;
        });
    }

    public function test_tc2_invoice_without_je_gap_does_not_appear(): void
    {
        // HĐ draft (chưa duyệt) không thuộc phạm vi check E001 — không được xuất hiện
        $pi = PurchaseInvoice::create([
            'code' => 'HD-NCC-DASHTC2', 'purchase_order_id' => $this->po->id, 'supplier_id' => $this->supplier->id,
            'subtotal' => 5_000_000, 'tax_amount' => 500_000, 'total' => 5_500_000, 'paid_amount' => 0,
            'status' => PurchaseInvoiceStatus::Pending, 'invoice_type' => null,
            'invoice_date' => now()->toDateString(), 'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user);
        $response = $this->get(route('dashboard'));
        $response->assertOk();

        $response->assertInertia(function ($page) use ($pi) {
            $findings = $page->toArray()['props']['journalAuditFindings'];
            $match = collect($findings)->firstWhere('document_code', $pi->code);
            $this->assertNull($match, 'HĐ status=pending (chưa duyệt) không thuộc phạm vi E001, không được xuất hiện.');
            return true;
        });
    }
}
