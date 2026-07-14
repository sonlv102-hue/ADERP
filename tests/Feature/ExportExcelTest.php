<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExportExcelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $perms = [
            'quotations.view',
            'accounting.view',
            'invoices.view',
            'purchasing.view',
            'projects.view',
        ];
        foreach ($perms as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo($perms);
    }

    public function test_quotations_export_returns_excel(): void
    {
        $response = $this->get(route('sales.quotations.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_sales_orders_export_returns_excel(): void
    {
        $response = $this->get(route('sales.orders.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_invoices_export_returns_excel(): void
    {
        $response = $this->get(route('accounting.invoices.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_cash_vouchers_export_returns_excel(): void
    {
        $response = $this->get(route('accounting.cash-vouchers.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_cash_vouchers_receipt_filter_export(): void
    {
        $response = $this->get(route('accounting.cash-vouchers.export-excel', ['type' => 'receipt']));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_journal_entries_export_returns_excel(): void
    {
        $response = $this->get(route('accounting.journal-entries.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_bank_transactions_export_returns_excel(): void
    {
        $bankAccount = BankAccount::create([
            'name'           => 'Test Bank',
            'bank_name'      => 'Vietcombank',
            'account_number' => '1234567890',
            'account_name'   => 'Test Company',
            'account_code'   => '1121',
            'opening_balance'=> 0,
        ]);

        $response = $this->get(route('accounting.bank-accounts.transactions.export-excel', $bankAccount));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_purchase_orders_export_returns_excel(): void
    {
        $response = $this->get(route('purchasing.purchase-orders.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_purchase_invoices_export_returns_excel(): void
    {
        $response = $this->get(route('purchasing.purchase-invoices.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_purchase_contracts_export_returns_excel(): void
    {
        $response = $this->get(route('purchasing.purchase-contracts.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_projects_export_returns_excel(): void
    {
        $response = $this->get(route('projects.projects.export-excel'));
        $response->assertOk();
        $this->assertExcelResponse($response);
    }

    public function test_export_requires_auth(): void
    {
        $this->post('/logout');
        auth()->logout();

        $response = $this->get(route('sales.quotations.export-excel'));
        $response->assertRedirect(route('login'));
    }

    public function test_export_requires_permission(): void
    {
        $noPermUser = User::factory()->create();
        $this->actingAs($noPermUser);

        $response = $this->get(route('sales.quotations.export-excel'));
        $response->assertStatus(403);
    }

    private function assertExcelResponse($response): void
    {
        $contentType = $response->headers->get('Content-Type');
        $this->assertStringContainsString(
            'spreadsheetml',
            $contentType ?? '',
            "Expected Excel content-type, got: {$contentType}"
        );
    }
}
