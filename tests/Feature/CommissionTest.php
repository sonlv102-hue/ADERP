<?php

namespace Tests\Feature;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use App\Enums\OrderStatus;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionTest extends TestCase
{
    use RefreshDatabase;

    private User     $user;
    private Customer $customer;
    private Order    $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::firstOrCreate(
            ['email' => 'commission-test@test.local'],
            ['name' => 'Commission Tester', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        foreach (['commissions.view', 'commissions.create'] as $perm) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm]);
        }
        $this->user->givePermissionTo(['commissions.view', 'commissions.create']);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'code'      => 'KH-CM01',
            'name'      => 'Khách hàng Hoa Hồng',
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'code'        => 'DH-CM01',
            'customer_id' => $this->customer->id,
            'status'      => OrderStatus::Processing,
            'created_by'  => $this->user->id,
            'order_date'  => now()->toDateString(),
        ]);
    }

    // ── Index ────────────────────────────────────────────────────────────

    public function test_index_returns_commissions(): void
    {
        Commission::create($this->baseData());

        $this->get(route('sales.commissions.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('commissions.data', 1));
    }

    public function test_index_filter_by_order_code(): void
    {
        Commission::create($this->baseData(['order_id' => $this->order->id, 'code' => 'HOA-F001']));
        Commission::create($this->baseData(['code' => 'HOA-F002'])); // no order

        $this->get(route('sales.commissions.index', ['order_code' => 'DH-CM01']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('commissions.data', 1)
                ->where('commissions.data.0.code', 'HOA-F001')
            );
    }

    public function test_index_filter_by_status(): void
    {
        Commission::create($this->baseData(['code' => 'HOA-S001', 'status' => CommissionStatus::Draft]));
        Commission::create($this->baseData(['code' => 'HOA-S002', 'status' => CommissionStatus::Cancelled]));

        $this->get(route('sales.commissions.index', ['status' => 'cancelled']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('commissions.data', 1)
                ->where('commissions.data.0.code', 'HOA-S002')
            );
    }

    // ── Create / Store ────────────────────────────────────────────────────

    public function test_store_creates_commission_without_order(): void
    {
        $this->post(route('sales.commissions.store'), [
            'code'           => 'HOA-0001',
            'type'           => CommissionType::Referral->value,
            'recipient_name' => 'Nguyễn Văn A',
            'amount'         => 2000000,
            'payment_method' => 'bank_transfer',
        ])->assertRedirect();

        $this->assertDatabaseHas('commissions', [
            'code'       => 'HOA-0001',
            'status'     => 'draft',
            'order_id'   => null,
        ]);
    }

    public function test_store_creates_commission_with_order_id(): void
    {
        $this->post(route('sales.commissions.store'), [
            'code'           => 'HOA-0002',
            'type'           => CommissionType::Brokerage->value,
            'recipient_name' => 'Trần Thị B',
            'amount'         => 5000000,
            'payment_method' => 'cash',
            'order_id'       => $this->order->id,
            'customer_id'    => $this->customer->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('commissions', [
            'code'        => 'HOA-0002',
            'order_id'    => $this->order->id,
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->post(route('sales.commissions.store'), [])
            ->assertSessionHasErrors(['code', 'type', 'recipient_name', 'amount']);
    }

    public function test_store_validates_order_id_exists(): void
    {
        $this->post(route('sales.commissions.store'), [
            'code'           => 'HOA-0003',
            'type'           => CommissionType::Referral->value,
            'recipient_name' => 'Test',
            'amount'         => 1000,
            'payment_method' => 'cash',
            'order_id'       => 99999,
        ])->assertSessionHasErrors(['order_id']);
    }

    // ── Show ─────────────────────────────────────────────────────────────

    public function test_show_includes_order_link(): void
    {
        $commission = Commission::create($this->baseData([
            'order_id' => $this->order->id,
        ]));

        $this->get(route('sales.commissions.show', $commission))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('commission.order', 'DH-CM01')
                ->where('commission.order_id', $this->order->id)
            );
    }

    public function test_show_order_is_null_when_not_linked(): void
    {
        $commission = Commission::create($this->baseData());

        $this->get(route('sales.commissions.show', $commission))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('commission.order', null)
                ->where('commission.order_id', null)
            );
    }

    // ── Edit / Update ─────────────────────────────────────────────────────

    public function test_update_can_set_order_id(): void
    {
        $commission = Commission::create($this->baseData());
        $this->assertNull($commission->order_id);

        $this->put(route('sales.commissions.update', $commission), [
            'type'           => CommissionType::Referral->value,
            'recipient_name' => 'Updated Name',
            'amount'         => 3000000,
            'payment_method' => 'bank_transfer',
            'order_id'       => $this->order->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('commissions', [
            'id'       => $commission->id,
            'order_id' => $this->order->id,
        ]);
    }

    public function test_update_can_clear_order_id(): void
    {
        $commission = Commission::create($this->baseData(['order_id' => $this->order->id]));

        $this->put(route('sales.commissions.update', $commission), [
            'type'           => CommissionType::Referral->value,
            'recipient_name' => 'Kept Name',
            'amount'         => 2000000,
            'payment_method' => 'bank_transfer',
            'order_id'       => null,
        ])->assertRedirect();

        $this->assertDatabaseHas('commissions', [
            'id'       => $commission->id,
            'order_id' => null,
        ]);
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    public function test_destroy_draft_commission(): void
    {
        $commission = Commission::create($this->baseData());

        $this->delete(route('sales.commissions.destroy', $commission))
            ->assertRedirect(route('sales.commissions.index'));

        $this->assertDatabaseMissing('commissions', ['id' => $commission->id]);
    }

    public function test_destroy_non_draft_commission_is_rejected(): void
    {
        $commission = Commission::create($this->baseData(['status' => CommissionStatus::PendingL1]));

        $this->delete(route('sales.commissions.destroy', $commission))
            ->assertRedirect();

        $this->assertDatabaseHas('commissions', ['id' => $commission->id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'code'           => 'HOA-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT),
            'type'           => CommissionType::Referral,
            'recipient_name' => 'Test Recipient',
            'amount'         => 1000000,
            'payment_method' => 'bank_transfer',
            'status'         => CommissionStatus::Draft,
            'created_by'     => $this->user->id,
        ], $overrides);
    }
}
