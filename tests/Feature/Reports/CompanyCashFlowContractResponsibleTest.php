<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\CashFlowClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** Hardening pass — spec §1/§12: UI hợp đồng + người phụ trách trên ClassifyModal. */
class CompanyCashFlowContractResponsibleTest extends TestCase
{
    use RefreshDatabase;

    private CashFlowClassificationService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]);
        $this->actingAs($this->admin);
        $this->service = app(CashFlowClassificationService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function tx(): BankTransaction
    {
        $acc = BankAccount::create(['name' => 'VCB', 'bank_name' => 'VCB', 'account_number' => (string) rand(1000, 9999), 'opening_balance' => 0, 'is_active' => true]);

        return BankTransaction::create([
            'bank_account_id' => $acc->id, 'transaction_date' => '2026-09-10',
            'description' => 'GD test', 'credit' => 100,
        ]);
    }

    /** Responsible user: valid user accepted. */
    public function test_valid_responsible_user_accepted(): void
    {
        $t = $this->tx();
        $user = User::factory()->create(['is_active' => true]);

        $this->service->update($t, ['responsible_user_id' => $user->id]);

        $this->assertSame($user->id, $t->fresh()->responsible_user_id);
    }

    /** Responsible user: invalid user rejected — chặn ở SERVICE, không chỉ Laravel request rule. */
    public function test_invalid_responsible_user_rejected(): void
    {
        $t = $this->tx();

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['responsible_user_id' => 999999]);
    }

    /** DTO transactions() JSON trả responsible_user_id (không chỉ tên) để modal pre-fill đúng. */
    public function test_transactions_json_exposes_responsible_user_id(): void
    {
        $acc = $t = $this->tx();
        $user = User::factory()->create(['is_active' => true]);
        $this->service->update($t, ['responsible_user_id' => $user->id]);

        $response = $this->getJson(route('reports.company-cashflow.transactions', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $row = collect($response->json('data'))->firstWhere('id', $t->id);

        $this->assertSame($user->id, $row['responsible_user_id']);
        $this->assertSame($user->name, $row['responsible_user_name']);
    }

    /** Editing category does not clear responsible user đã gán trước đó. */
    public function test_editing_category_does_not_clear_responsible_user(): void
    {
        $t = $this->tx();
        $user = User::factory()->create(['is_active' => true]);
        $category = \App\Models\CashFlowCategory::where('code', 'in_customer_payment')->first();
        $this->service->update($t, ['responsible_user_id' => $user->id]);

        $this->service->update($t, ['cash_flow_category_id' => $category->id]);

        $this->assertSame($user->id, $t->fresh()->responsible_user_id);
    }

    /** Contract: wrong contract_type rejected. */
    public function test_wrong_contract_type_rejected(): void
    {
        $t = $this->tx();

        $this->expectException(InvalidArgumentException::class);
        $this->service->update($t, ['contract_type' => 'not_a_real_type', 'contract_id' => 1]);
    }

    /** Reopening DTO trả đủ contract_type/contract_id/contract_label để modal pre-fill đúng. */
    public function test_transactions_json_exposes_contract_fields(): void
    {
        $t = $this->tx();
        $customer = Customer::create(['code' => 'KH-CR1', 'name' => 'KH CR1', 'is_active' => true]);
        $contract = Contract::create(['code' => 'HD-CR1', 'customer_id' => $customer->id, 'title' => 'HĐ CR1', 'status' => 'draft', 'created_by' => $this->admin->id]);
        $this->service->update($t, ['contract_type' => 'contract', 'contract_id' => $contract->id]);

        $response = $this->getJson(route('reports.company-cashflow.transactions', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $row = collect($response->json('data'))->firstWhere('id', $t->id);

        $this->assertSame('contract', $row['contract_type']);
        $this->assertSame($contract->id, $row['contract_id']);
        $this->assertStringContainsString($contract->code, $row['contract_label']);
    }

    /** Editing note does not clear contract đã liên kết trước đó (regression data-loss). */
    public function test_editing_note_does_not_clear_contract(): void
    {
        $t = $this->tx();
        $customer = Customer::create(['code' => 'KH-CR2', 'name' => 'KH CR2', 'is_active' => true]);
        $contract = Contract::create(['code' => 'HD-CR2', 'customer_id' => $customer->id, 'title' => 'HĐ CR2', 'status' => 'draft', 'created_by' => $this->admin->id]);
        $this->service->update($t, ['contract_type' => 'contract', 'contract_id' => $contract->id]);

        $this->service->update($t, ['cash_flow_note' => 'chỉ sửa note']);

        $fresh = $t->fresh();
        $this->assertSame($contract->id, $fresh->contract_id);
        $this->assertSame('contract', $fresh->contract_type);
        $this->assertSame('chỉ sửa note', $fresh->cash_flow_note);
    }
}
