<?php

namespace Tests\Feature\Sales;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test 1: Không có sales.invoices.view -> không vào được danh sách hóa đơn bán (403).
 * Test 2: Có view nhưng không có create -> xem được (200), nhưng POST store bị 403.
 * Test 3: Không có sales.invoices.post -> không ghi sổ (mark-sent) được.
 * Test 4: Không có sales.invoices.cancel (hoặc thiếu reverse) -> không hủy được.
 * Test 5: Không có sales.invoices.export -> không xuất Excel/PDF được.
 * Test 6: Role Kinh doanh chỉ có quyền tạo/sửa/xem/xuất, không có ghi sổ/đảo.
 * Test 7: Super Admin có toàn quyền.
 */
class SalesInvoicePermissionTest extends TestCase
{
    use RefreshDatabase;

    private int $roleSeq = 0;

    private int $creatorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccounts();
        $this->creatorId = User::factory()->create(['is_active' => true])->id;
    }

    private function seedAccounts(): void
    {
        foreach ([
            ['code' => '131',   'name' => 'Phải thu KH',      'type' => 'asset',    'normal_balance' => 'debit',  'is_detail' => false],
            ['code' => '1311',  'name' => 'Phải thu KH - DN', 'type' => 'asset',    'normal_balance' => 'debit',  'is_detail' => true],
            ['code' => '5111',  'name' => 'DT hàng hóa',      'type' => 'revenue',  'normal_balance' => 'credit', 'is_detail' => true],
            ['code' => '33311', 'name' => 'Thuế GTGT đầu ra', 'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true],
        ] as $ac) {
            AccountCode::firstOrCreate(['code' => $ac['code']], array_merge($ac, ['level' => 3, 'parent_code' => null]));
        }
        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'code'                    => 'KH-INV-' . uniqid(),
            'name'                    => 'Test Customer',
            'receivable_account_code' => '1311',
        ]);
    }

    private function makeInvoice(): Invoice
    {
        return Invoice::create([
            'code'        => 'HD-TEST-' . uniqid(),
            'customer_id' => $this->makeCustomer()->id,
            'issue_date'  => '2026-06-15',
            'subtotal'    => 1_000_000,
            'tax_amount'  => 100_000,
            'total'       => 1_100_000,
            'created_by'  => $this->creatorId,
        ]);
    }

    private function makeUserWithPermissions(array $codes): User
    {
        $this->roleSeq++;
        $role = Role::create(['name' => 'Test Role ' . $this->roleSeq, 'code' => 'test_role_' . $this->roleSeq]);
        $ids  = Permission::whereIn('code', $codes)->pluck('id');
        $role->permissions()->sync($ids);

        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function ensurePermissions(): void
    {
        $defs = [
            'sales.invoices.view', 'sales.invoices.create', 'sales.invoices.update', 'sales.invoices.delete',
            'sales.invoices.approve', 'sales.invoices.cancel', 'sales.invoices.post', 'sales.invoices.reverse',
            'sales.invoices.export', 'sales.invoices.import',
        ];
        foreach ($defs as $code) {
            Permission::firstOrCreate(['code' => $code], ['name' => $code]);
        }
    }

    // ─── Test 1 ──────────────────────────────────────────────────────────────
    public function test_user_without_view_cannot_access_invoice_list(): void
    {
        $this->ensurePermissions();
        $user = $this->makeUserWithPermissions([]);
        $this->actingAs($user);

        $this->get(route('accounting.invoices.index'))->assertForbidden();
    }

    // ─── Test 2 ──────────────────────────────────────────────────────────────
    public function test_user_with_view_only_can_see_list_but_not_create(): void
    {
        $this->ensurePermissions();
        $user = $this->makeUserWithPermissions(['sales.invoices.view']);
        $this->actingAs($user);

        $this->get(route('accounting.invoices.index'))->assertOk();

        $this->post(route('accounting.invoices.store'), [])->assertForbidden();
    }

    // ─── Test 3 ──────────────────────────────────────────────────────────────
    public function test_user_without_post_cannot_mark_sent(): void
    {
        $this->ensurePermissions();
        $invoice = $this->makeInvoice();
        $user = $this->makeUserWithPermissions(['sales.invoices.view', 'sales.invoices.update']);
        $this->actingAs($user);

        $this->post(route('accounting.invoices.mark-sent', $invoice))->assertForbidden();
    }

    // ─── Test 4 ──────────────────────────────────────────────────────────────
    public function test_user_without_cancel_cannot_cancel_invoice(): void
    {
        $this->ensurePermissions();
        $invoice = $this->makeInvoice();
        $user = $this->makeUserWithPermissions(['sales.invoices.view', 'sales.invoices.update']);
        $this->actingAs($user);

        $this->post(route('accounting.invoices.cancel', $invoice))->assertForbidden();

        // Có cancel nhưng thiếu reverse cũng vẫn bị chặn (route đòi cả 2)
        $user2 = $this->makeUserWithPermissions(['sales.invoices.view', 'sales.invoices.cancel']);
        $this->actingAs($user2);
        $this->post(route('accounting.invoices.cancel', $invoice))->assertForbidden();
    }

    // ─── Test 5 ──────────────────────────────────────────────────────────────
    public function test_user_without_export_cannot_export(): void
    {
        $this->ensurePermissions();
        $invoice = $this->makeInvoice();
        $user = $this->makeUserWithPermissions(['sales.invoices.view']);
        $this->actingAs($user);

        $this->get(route('accounting.invoices.export-excel'))->assertForbidden();
        $this->get(route('accounting.invoices.pdf', $invoice))->assertForbidden();
    }

    // ─── Test 6 ──────────────────────────────────────────────────────────────
    public function test_sales_role_has_crud_export_but_not_post_or_reverse(): void
    {
        $this->ensurePermissions();
        $invoice = $this->makeInvoice();
        $user = $this->makeUserWithPermissions([
            'sales.invoices.view', 'sales.invoices.create', 'sales.invoices.update', 'sales.invoices.export',
        ]);
        $this->actingAs($user);

        $this->get(route('accounting.invoices.index'))->assertOk();
        $this->get(route('accounting.invoices.create'))->assertOk();
        $this->get(route('accounting.invoices.export-excel'))->assertOk();

        $this->post(route('accounting.invoices.mark-sent', $invoice))->assertForbidden();
        $this->post(route('accounting.invoices.cancel', $invoice))->assertForbidden();
    }

    // ─── Test 7 ──────────────────────────────────────────────────────────────
    public function test_super_admin_has_full_access(): void
    {
        $this->ensurePermissions();
        $invoice = $this->makeInvoice();
        $role = Role::firstOrCreate(['code' => 'super_admin'], ['name' => 'Super Admin']);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);
        $this->actingAs($user);

        $this->get(route('accounting.invoices.index'))->assertOk();
        $this->get(route('accounting.invoices.export-excel'))->assertOk();
        $this->post(route('accounting.invoices.mark-sent', $invoice))->assertRedirect();
        $invoice->refresh();
        $this->post(route('accounting.invoices.cancel', $invoice))->assertRedirect();
    }
}
