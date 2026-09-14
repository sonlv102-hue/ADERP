<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseContract;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'search-test@test.local'],
            ['name' => 'Search Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
    }

    // ── Supplier search ──────────────────────────────────────────────────

    public function test_search_suppliers_by_name(): void
    {
        Supplier::create(['code' => 'NCC-S01', 'name' => 'Công ty TNHH ABC', 'is_active' => true]);
        Supplier::create(['code' => 'NCC-S02', 'name' => 'Doanh nghiệp XYZ', 'is_active' => true]);

        $res = $this->getJson('/api/search/suppliers?q=ABC');
        $res->assertOk()->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.label', 'Công ty TNHH ABC');
    }

    public function test_search_suppliers_by_tax_code(): void
    {
        Supplier::create(['code' => 'NCC-S03', 'name' => 'Công ty Thuế A', 'is_active' => true, 'tax_code' => '0123456789']);

        $res = $this->getJson('/api/search/suppliers?q=0123456789');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        $this->assertEquals('Công ty Thuế A', $res->json('data.0.label'));
    }

    public function test_search_suppliers_by_phone(): void
    {
        Supplier::create(['code' => 'NCC-S04', 'name' => 'Công ty Phone A', 'is_active' => true, 'phone' => '0901234567']);

        $res = $this->getJson('/api/search/suppliers?q=0901234567');
        $res->assertOk();
        $this->assertEquals('Công ty Phone A', $res->json('data.0.label'));
    }

    public function test_search_suppliers_returns_payable_account_code(): void
    {
        Supplier::create(['code' => 'NCC-S05', 'name' => 'Supplier PayableTest', 'is_active' => true, 'payable_account_code' => '3312']);

        $res = $this->getJson('/api/search/suppliers?q=PayableTest');
        $res->assertOk();
        $this->assertEquals('3312', $res->json('data.0.payable_account_code'));
    }

    public function test_search_suppliers_excludes_inactive(): void
    {
        Supplier::create(['code' => 'NCC-S06', 'name' => 'Inactive Supplier', 'is_active' => false]);

        $res = $this->getJson('/api/search/suppliers?q=Inactive');
        $res->assertOk()->assertJsonCount(0, 'data');
    }

    // ── Customer search ──────────────────────────────────────────────────

    public function test_search_customers_by_name(): void
    {
        Customer::create(['code' => 'KH-C01', 'name' => 'Khách hàng Bình Dương', 'is_active' => true]);

        $res = $this->getJson('/api/search/customers?q=Bình Dương');
        $res->assertOk();
        $this->assertEquals('Khách hàng Bình Dương', $res->json('data.0.label'));
    }

    public function test_search_customers_by_tax_code(): void
    {
        Customer::create(['code' => 'KH-C02', 'name' => 'Khách hàng MST', 'is_active' => true, 'tax_code' => '9876543210']);

        $res = $this->getJson('/api/search/customers?q=9876543210');
        $res->assertOk();
        $this->assertEquals('Khách hàng MST', $res->json('data.0.label'));
    }

    public function test_search_customers_returns_receivable_account_code(): void
    {
        \App\Models\AccountCode::firstOrCreate(
            ['code' => '1312'],
            ['name' => 'Phải thu KH FDI', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true, 'is_detail' => true]
        );
        Customer::create(['code' => 'KH-C03', 'name' => 'Customer Receivable', 'is_active' => true, 'receivable_account_code' => '1312']);

        $res = $this->getJson('/api/search/customers?q=Receivable');
        $res->assertOk();
        $this->assertEquals('1312', $res->json('data.0.receivable_account_code'));
    }

    // ── Product search ───────────────────────────────────────────────────

    public function test_search_products_by_name(): void
    {
        Product::create([
            'code' => 'SP-P01', 'name' => 'Máy tính Dell XPS', 'is_active' => true,
            'unit' => 'cái', 'item_type' => 'goods',
        ]);

        $res = $this->getJson('/api/search/products?q=Dell');
        $res->assertOk();
        $this->assertEquals('Máy tính Dell XPS', $res->json('data.0.label'));
    }

    public function test_search_products_by_code(): void
    {
        Product::create([
            'code' => 'SP-XYZ99', 'name' => 'Sản phẩm XYZ', 'is_active' => true,
            'unit' => 'cái', 'item_type' => 'goods',
        ]);

        $res = $this->getJson('/api/search/products?q=SP-XYZ99');
        $res->assertOk();
        $this->assertEquals('Sản phẩm XYZ', $res->json('data.0.label'));
    }

    public function test_search_products_by_category_name(): void
    {
        $category = ProductCategory::create(['name' => 'Thiết bị mạng', 'slug' => 'thiet-bi-mang']);
        Product::create([
            'code' => 'SP-P02', 'name' => 'Switch 24 port', 'is_active' => true,
            'category_id' => $category->id, 'unit' => 'cái', 'item_type' => 'goods',
        ]);
        Product::create([
            'code' => 'SP-P03', 'name' => 'Laptop HP Elitebook', 'is_active' => true,
            'unit' => 'cái', 'item_type' => 'goods',
        ]);

        $res = $this->getJson('/api/search/products?q=Thiết bị mạng');
        $res->assertOk()->assertJsonCount(1, 'data');
        $this->assertEquals('Switch 24 port', $res->json('data.0.label'));
        $this->assertEquals('Thiết bị mạng', $res->json('data.0.category_name'));
    }

    public function test_search_products_excludes_inactive(): void
    {
        Product::create([
            'code' => 'SP-INACTIVE', 'name' => 'Inactive Product', 'is_active' => false,
            'unit' => 'cái', 'item_type' => 'goods',
        ]);

        $res = $this->getJson('/api/search/products?q=Inactive');
        $res->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_limit_capped_at_50(): void
    {
        $res = $this->getJson('/api/search/suppliers?q=&limit=999');
        $res->assertOk();
        $this->assertLessThanOrEqual(50, count($res->json('data')));
    }

    // ── Order search ─────────────────────────────────────────────────────

    public function test_search_orders_by_code(): void
    {
        $customer = Customer::create(['code' => 'KH-SO01', 'name' => 'KH Search Order', 'is_active' => true]);
        Order::create([
            'code'        => 'DH-SRCH1',
            'customer_id' => $customer->id,
            'status'      => OrderStatus::Processing,
            'created_by'  => $this->user->id,
            'order_date'  => now()->toDateString(),
        ]);

        $res = $this->getJson('/api/search/orders?q=DH-SRCH1');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        // label = customer name; code = order code
        $this->assertEquals('DH-SRCH1', $res->json('data.0.code'));
        $this->assertEquals($customer->name, $res->json('data.0.label'));
    }

    public function test_search_orders_by_customer_name(): void
    {
        $customer = Customer::create(['code' => 'KH-SO02', 'name' => 'Khách Hàng Đặc Biệt', 'is_active' => true]);
        Order::create([
            'code'        => 'DH-SRCH2',
            'customer_id' => $customer->id,
            'status'      => OrderStatus::Processing,
            'created_by'  => $this->user->id,
            'order_date'  => now()->toDateString(),
        ]);

        // Query dùng chữ cái đầu ASCII ("Biệt") — sqlite LOWER() không hạ được
        // ký tự hoa Unicode ("Đ") nên fixture "Đặc" không so khớp trên sqlite;
        // trên PostgreSQL (LOWER() chuẩn Unicode) mọi biến thể đều khớp.
        $res = $this->getJson('/api/search/orders?q=Biệt');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        // label = customer name khi tìm theo tên khách
        $found = collect($res->json('data'))->firstWhere('code', 'DH-SRCH2');
        $this->assertNotNull($found);
        $this->assertEquals('Khách Hàng Đặc Biệt', $found['label']);
    }

    public function test_search_orders_returns_value_and_meta(): void
    {
        $customer = Customer::create(['code' => 'KH-SO03', 'name' => 'KH Meta Test', 'is_active' => true]);
        $order = Order::create([
            'code'        => 'DH-SRCH3',
            'customer_id' => $customer->id,
            'status'      => OrderStatus::Pending,
            'created_by'  => $this->user->id,
            'order_date'  => now()->toDateString(),
        ]);

        $res = $this->getJson('/api/search/orders?q=DH-SRCH3');
        $res->assertOk();
        $item = $res->json('data.0');
        $this->assertEquals($order->id, $item['value']);
        $this->assertEquals('DH-SRCH3', $item['code']);       // code = order code
        $this->assertEquals('KH Meta Test', $item['label']);  // label = customer name
        $this->assertEquals('KH Meta Test', $item['customer_name']);
    }

    // ── Case-insensitive search (bug fix: PostgreSQL LIKE phân biệt hoa/thường) ──

    /**
     * @dataProvider viCaseVariants
     */
    public function test_search_suppliers_case_insensitive_vietnamese(string $needle): void
    {
        Supplier::create(['code' => 'NCC-CI1', 'name' => 'Công ty Nguyễn Văn An', 'is_active' => true]);

        $res = $this->getJson('/api/search/suppliers?q=' . urlencode($needle));
        $res->assertOk();
        $this->assertEquals('Công ty Nguyễn Văn An', $res->json('data.0.label'), "q={$needle}");
    }

    /**
     * @dataProvider viCaseVariants
     */
    public function test_search_customers_case_insensitive_vietnamese(string $needle): void
    {
        Customer::create(['code' => 'KH-CI1', 'name' => 'Nguyễn Văn An Company', 'is_active' => true]);

        $res = $this->getJson('/api/search/customers?q=' . urlencode($needle));
        $res->assertOk();
        $this->assertEquals('Nguyễn Văn An Company', $res->json('data.0.label'), "q={$needle}");
    }

    /**
     * @dataProvider asciiCaseVariants
     */
    public function test_search_products_case_insensitive_ascii(string $needle): void
    {
        Product::create([
            'code' => 'SP-CI1', 'name' => 'ABC Router', 'is_active' => true,
            'unit' => 'cái', 'item_type' => 'goods',
        ]);

        $res = $this->getJson('/api/search/products?q=' . urlencode($needle));
        $res->assertOk();
        $this->assertEquals('ABC Router', $res->json('data.0.label'), "q={$needle}");
    }

    /** Không được match khi khác dấu — fix này chỉ xử lý hoa/thường, không phải bỏ dấu. */
    public function test_search_stays_accent_sensitive(): void
    {
        Supplier::create(['code' => 'NCC-CI2', 'name' => 'Công ty Nguyễn', 'is_active' => true]);

        $res = $this->getJson('/api/search/suppliers?q=Nguyen');
        $res->assertOk()->assertJsonCount(0, 'data');
    }

    public static function viCaseVariants(): array
    {
        return [
            'as-is'      => ['Nguyễn'],
            'lower'      => ['nguyễn'],
            'upper'      => ['NGUYỄN'],
            'mixed'      => ['NgUyỄn'],
        ];
    }

    public static function asciiCaseVariants(): array
    {
        return [['ABC'], ['abc'], ['Abc']];
    }

    // ── Authorization: search.contracts / search.users / search.purchase-contracts ──
    // Phát hiện qua pre-deploy audit Cash Flow: 3 endpoint này chỉ nằm trong middleware
    // 'auth' chung (giống các search endpoint cũ ở trên), không có permission gate riêng —
    // lộ tên khách hàng/hợp đồng và PII nhân viên (tên+email) cho MỌI user đã login.

    private function roleWithPermissions(string $code, array $permissionCodes): Role
    {
        $role = Role::create(['code' => $code, 'name' => $code, 'is_system' => false]);
        foreach ($permissionCodes as $permCode) {
            $permission = Permission::firstOrCreate(['code' => $permCode], ['name' => $permCode, 'module' => 'test']);
            $role->permissions()->attach($permission->id);
        }
        return $role;
    }

    public function test_search_contracts_rejects_unauthenticated(): void
    {
        auth()->logout();
        $this->getJson('/api/search/contracts')->assertUnauthorized();
    }

    public function test_search_contracts_rejects_user_without_reconcile_permission(): void
    {
        $lowRole = $this->roleWithPermissions('search_contracts_low', ['reports.bank_cashflow.view']);
        $this->user->roles()->sync([$lowRole->id]);

        $this->getJson('/api/search/contracts?q=HD')->assertForbidden();
    }

    public function test_search_contracts_allows_user_with_reconcile_permission(): void
    {
        $customer = Customer::create(['code' => 'KH-SC1', 'name' => 'KH Search Contract', 'is_active' => true]);
        Contract::create(['code' => 'HD-SC1', 'customer_id' => $customer->id, 'title' => 'HĐ search test', 'status' => 'draft', 'created_by' => $this->user->id]);
        $role = $this->roleWithPermissions('search_contracts_ok', ['reports.bank_cashflow.reconcile']);
        $this->user->roles()->sync([$role->id]);

        $res = $this->getJson('/api/search/contracts?q=HD-SC1');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_search_users_rejects_unauthenticated(): void
    {
        auth()->logout();
        $this->getJson('/api/search/users')->assertUnauthorized();
    }

    /** Endpoint mới lộ PII (tên+email) — user chỉ có quyền view (không reconcile) không được enumerate. */
    public function test_search_users_rejects_user_without_reconcile_permission(): void
    {
        $lowRole = $this->roleWithPermissions('search_users_low', ['reports.bank_cashflow.view']);
        $this->user->roles()->sync([$lowRole->id]);

        $this->getJson('/api/search/users?q=a')->assertForbidden();
    }

    public function test_search_users_allows_user_with_reconcile_permission(): void
    {
        $role = $this->roleWithPermissions('search_users_ok', ['reports.bank_cashflow.reconcile']);
        $this->user->roles()->sync([$role->id]);

        $res = $this->getJson('/api/search/users?q=' . urlencode($this->user->name));
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    /**
     * Route dùng chung 2 module: Purchasing/SupplierAdvances/Form.vue (cần purchasing.view)
     * và ClassifyModal.vue của Cash Flow (cần reports.bank_cashflow.reconcile). Cả 2 phía
     * phải tiếp tục hoạt động — không được siết chỉ còn 1 quyền (sẽ phá luồng tạm ứng NCC).
     */
    public function test_search_purchase_contracts_allows_purchasing_view_caller_no_regression(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-SPC1', 'name' => 'NCC Search PC', 'is_active' => true]);
        PurchaseContract::create([
            'code' => 'HD-MH-SPC1', 'supplier_id' => $supplier->id, 'title' => 'HĐ mua search test',
            'value' => 1_000_000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => \App\Enums\PurchaseContractStatus::Draft, 'created_by' => $this->user->id,
        ]);
        $role = $this->roleWithPermissions('search_pc_purchasing', ['purchasing.view']);
        $this->user->roles()->sync([$role->id]);

        $res = $this->getJson('/api/search/purchase-contracts?q=HD-MH-SPC1');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_search_purchase_contracts_allows_cashflow_reconcile_caller(): void
    {
        $supplier = Supplier::create(['code' => 'NCC-SPC2', 'name' => 'NCC Search PC 2', 'is_active' => true]);
        PurchaseContract::create([
            'code' => 'HD-MH-SPC2', 'supplier_id' => $supplier->id, 'title' => 'HĐ mua search test 2',
            'value' => 1_000_000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => \App\Enums\PurchaseContractStatus::Draft, 'created_by' => $this->user->id,
        ]);
        $role = $this->roleWithPermissions('search_pc_cashflow', ['reports.bank_cashflow.reconcile']);
        $this->user->roles()->sync([$role->id]);

        $res = $this->getJson('/api/search/purchase-contracts?q=HD-MH-SPC2');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
    }

    public function test_search_purchase_contracts_rejects_user_with_neither_permission(): void
    {
        $role = $this->roleWithPermissions('search_pc_none', ['reports.bank_cashflow.view']);
        $this->user->roles()->sync([$role->id]);

        $this->getJson('/api/search/purchase-contracts?q=HD')->assertForbidden();
    }

    public function test_search_purchase_contracts_rejects_unauthenticated(): void
    {
        auth()->logout();
        $this->getJson('/api/search/purchase-contracts')->assertUnauthorized();
    }
}
