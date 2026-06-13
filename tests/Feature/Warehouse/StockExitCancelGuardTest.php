<?php

namespace Tests\Feature\Warehouse;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\StockExitStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\StockExit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

/**
 * M6: cancelExit() phải từ chối nếu đơn hàng đã có hóa đơn đang hoạt động.
 */
class StockExitCancelGuardTest extends TestCase
{
    use RefreshDatabase;

    private StockService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($user);
        Gate::before(fn ($user, $ability) => true);

        $this->service = app(StockService::class);
    }

    private function makeConfirmedExit(?Order $order = null): StockExit
    {
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TEST'],
            ['name' => 'Kho Test', 'address' => 'Test', 'manager_id' => null]
        );

        $exit = StockExit::create([
            'code'           => 'XK-TEST-' . uniqid(),
            'warehouse_id'   => $warehouse->id,
            'order_id'       => $order?->id,
            'status'         => StockExitStatus::Confirmed,
            'exit_date'      => now(),
            'total_quantity' => 0,
            'created_by'     => auth()->id(),
        ]);

        return $exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC1: Không có order_id → hủy bình thường (không ném lỗi invoice)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_cancel_exit_without_order_allowed(): void
    {
        $exit = $this->makeConfirmedExit(null);

        // Không throw từ invoice guard (có thể throw từ serial guard vì không có items — không quan tâm)
        try {
            $this->service->cancelExit($exit);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('hóa đơn', $e->getMessage(),
                'Lỗi phải không liên quan đến hóa đơn khi không có order_id');
        }

        // Miễn là không ném "không thể hủy phiếu xuất: đơn hàng đã có hóa đơn"
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC2: Có order_id nhưng không có hóa đơn → hủy được
    // ─────────────────────────────────────────────────────────────────────────
    public function test_cancel_exit_with_order_no_invoice_allowed(): void
    {
        $customer = Customer::create([
            'code' => 'KH-9901', 'name' => 'Khách Test',
            'phone' => '0900000000', 'email' => 'test@test.com',
            'address' => 'HN', 'is_active' => true,
        ]);
        $order = Order::create([
            'code' => 'DH-9901', 'customer_id' => $customer->id,
            'status' => OrderStatus::Pending, 'order_date' => now(),
            'created_by' => auth()->id(),
        ]);

        $exit = $this->makeConfirmedExit($order);

        // No active invoice → no block
        try {
            $this->service->cancelExit($exit);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('đã có hóa đơn', $e->getMessage());
        }

        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC3: Có order_id + hóa đơn sent → phải throw
    // ─────────────────────────────────────────────────────────────────────────
    public function test_cancel_exit_with_sent_invoice_throws(): void
    {
        $customer = Customer::create([
            'code' => 'KH-9902', 'name' => 'Khách Test 2',
            'phone' => '0900000001', 'email' => 'test2@test.com',
            'address' => 'HN', 'is_active' => true,
        ]);
        $order = Order::create([
            'code' => 'DH-9902', 'customer_id' => $customer->id,
            'status' => OrderStatus::Processing, 'order_date' => now(),
            'created_by' => auth()->id(),
        ]);
        Invoice::create([
            'code'        => 'HĐ-9902',
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'issue_date'  => now(),
            'status'      => InvoiceStatus::Sent,
            'amount_due'  => 1_000_000,
            'created_by'  => auth()->id(),
        ]);

        $exit = $this->makeConfirmedExit($order);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã có hóa đơn/');
        $this->service->cancelExit($exit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC4: Có order_id + hóa đơn overdue → phải throw
    // ─────────────────────────────────────────────────────────────────────────
    public function test_cancel_exit_with_overdue_invoice_throws(): void
    {
        $customer = Customer::create([
            'code' => 'KH-9903', 'name' => 'Khách Test 3',
            'phone' => '0900000002', 'email' => 'test3@test.com',
            'address' => 'HN', 'is_active' => true,
        ]);
        $order = Order::create([
            'code' => 'DH-9903', 'customer_id' => $customer->id,
            'status' => OrderStatus::Processing, 'order_date' => now(),
            'created_by' => auth()->id(),
        ]);
        Invoice::create([
            'code'        => 'HĐ-9903',
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'issue_date'  => now(),
            'status'      => InvoiceStatus::Overdue,
            'amount_due'  => 500_000,
            'created_by'  => auth()->id(),
        ]);

        $exit = $this->makeConfirmedExit($order);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã có hóa đơn/');
        $this->service->cancelExit($exit);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TC5: Hóa đơn draft → không block (draft chưa phải active)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_cancel_exit_with_draft_invoice_not_blocked(): void
    {
        $customer = Customer::create([
            'code' => 'KH-9904', 'name' => 'Khách Test 4',
            'phone' => '0900000003', 'email' => 'test4@test.com',
            'address' => 'HN', 'is_active' => true,
        ]);
        $order = Order::create([
            'code' => 'DH-9904', 'customer_id' => $customer->id,
            'status' => OrderStatus::Pending, 'order_date' => now(),
            'created_by' => auth()->id(),
        ]);
        Invoice::create([
            'code'        => 'HĐ-9904',
            'order_id'    => $order->id,
            'customer_id' => $customer->id,
            'issue_date'  => now(),
            'status'      => InvoiceStatus::Draft,
            'amount_due'  => 0,
            'created_by'  => auth()->id(),
        ]);

        $exit = $this->makeConfirmedExit($order);

        // Draft invoice should NOT block the cancellation
        try {
            $this->service->cancelExit($exit);
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('đã có hóa đơn', $e->getMessage(),
                'Hóa đơn draft không được block việc hủy phiếu xuất');
        }

        $this->assertTrue(true);
    }
}
