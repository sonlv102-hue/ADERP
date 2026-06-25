<?php

namespace Tests\Feature\Accounting;

use App\Enums\BankTransactionMatchStatus;
use App\Enums\BankTransactionStatus;
use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransactionAllocation;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BankTransactionAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BankTransactionAllocationTest extends TestCase
{
    use RefreshDatabase;

    private BankTransactionAllocationService $svc;
    private User $user;
    private BankAccount $bankAccount;
    private Supplier $supplier;
    private Customer $customer;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(BankTransactionAllocationService::class);

        $this->user = User::firstOrCreate(
            ['email' => 'admin@test.local'],
            ['name' => 'Admin', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($user, $ability) => true);

        AccountingPeriod::create(['year' => 2026, 'month' => 6, 'status' => 'open']);

        // TK cần thiết
        $this->seedAccount('112',  'asset',     'debit',  false);
        $this->seedAccount('1121', 'asset',     'debit',  true,  '112');
        $this->seedAccount('331',  'liability', 'credit', false);
        $this->seedAccount('3311', 'liability', 'credit', true,  '331');
        $this->seedAccount('131',  'asset',     'debit',  false);
        $this->seedAccount('1311', 'asset',     'debit',  true,  '131');

        $this->warehouse = Warehouse::create(['name' => 'Kho Test', 'code' => 'KHO-TEST']);

        $this->bankAccount = BankAccount::create([
            'name'            => 'Vietcombank',
            'bank_name'       => 'VCB',
            'account_number'  => '1234567890',
            'account_code'    => '1121',
            'currency'        => 'VND',
            'opening_balance' => 0,
            'is_active'       => true,
            'created_by'      => $this->user->id,
        ]);

        $this->supplier = Supplier::create([
            'code'                 => 'NCC-0001',
            'name'                 => 'Nhà cung cấp A',
            'payable_account_code' => '3311',
            'is_active'            => true,
        ]);

        $this->customer = Customer::create([
            'code'                    => 'KH-0001',
            'name'                    => 'Khách hàng A',
            'receivable_account_code' => '1311',
            'is_active'               => true,
        ]);
    }

    private function seedAccount(string $code, string $type, string $normalBalance, bool $isDetail, ?string $parentCode = null): void
    {
        if ($parentCode) {
            AccountCode::firstOrCreate(['code' => $parentCode], [
                'name' => 'TK ' . $parentCode, 'type' => $type,
                'normal_balance' => $normalBalance, 'parent_code' => null,
                'level' => 3, 'is_detail' => false, 'is_active' => true,
            ]);
        }
        AccountCode::firstOrCreate(['code' => $code], [
            'name'           => 'TK ' . $code,
            'type'           => $type,
            'normal_balance' => $normalBalance,
            'parent_code'    => $parentCode,
            'level'          => $parentCode ? 4 : 3,
            'is_detail'      => $isDetail,
            'is_active'      => true,
        ]);
    }

    private function makeDebitTx(int $amount = 10_000_000): BankTransaction
    {
        return BankTransaction::create([
            'bank_account_id'  => $this->bankAccount->id,
            'transaction_date' => '2026-06-15',
            'description'      => 'Thanh toán NCC',
            'debit'            => $amount,
            'credit'           => 0,
            'running_balance'  => 0,
            'status'           => BankTransactionStatus::Pending,
            'match_status'     => BankTransactionMatchStatus::Unmatched,
            'created_by'       => $this->user->id,
        ]);
    }

    private function makeCreditTx(int $amount = 5_000_000): BankTransaction
    {
        return BankTransaction::create([
            'bank_account_id'  => $this->bankAccount->id,
            'transaction_date' => '2026-06-15',
            'description'      => 'Thu từ khách hàng',
            'debit'            => 0,
            'credit'           => $amount,
            'running_balance'  => 0,
            'status'           => BankTransactionStatus::Pending,
            'match_status'     => BankTransactionMatchStatus::Unmatched,
            'created_by'       => $this->user->id,
        ]);
    }

    private function makePurchaseInvoice(int $total = 10_000_000, string $code = 'HD-NCC-001'): PurchaseInvoice
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-' . uniqid(),
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-06-01',
            'status'       => 'sent',
            'created_by'   => $this->user->id,
        ]);

        return PurchaseInvoice::create([
            'code'              => $code,
            'supplier_id'       => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'invoice_date'      => '2026-06-01',
            'subtotal'          => $total,
            'tax_amount'        => 0,
            'total'             => $total,
            'status'            => 'valid',
            'created_by'        => $this->user->id,
        ]);
    }

    private function makeInvoice(int $total = 5_000_000): Invoice
    {
        return Invoice::create([
            'code'        => 'HD-KH-001',
            'customer_id' => $this->customer->id,
            'issue_date'  => '2026-06-01',
            'subtotal'    => $total,
            'tax_amount'  => 0,
            'total'       => $total,
            'status'      => 'sent',
            'created_by'  => $this->user->id,
        ]);
    }

    // ─── TC1: Phân bổ 1 hóa đơn NCC → JE đúng ────────────────────────────────

    public function test_allocate_single_purchase_invoice_posts_correct_je(): void
    {
        $tx  = $this->makeDebitTx(10_000_000);
        $inv = $this->makePurchaseInvoice(10_000_000);

        $party = ['type' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name, 'code' => $this->supplier->code];
        $allocations = [[
            'type'         => 'purchase_invoice',
            'id'           => $inv->id,
            'amount'       => 10_000_000,
            'account_code' => '3311',
            'description'  => "Thanh toán HĐ {$inv->code}",
        ]];

        $je = $this->svc->allocate($tx, $party, $allocations);

        $this->assertNotNull($je);
        $this->assertSame('posted', $je->status);

        // Kiểm tra dòng bút toán: Dr 3311 / Cr 1121
        $lines = $je->lines()->get()->keyBy('account_code');
        $this->assertEquals(10_000_000, $lines['3311']->debit);
        $this->assertEquals(0,          $lines['3311']->credit);
        $this->assertEquals(0,          $lines['1121']->debit);
        $this->assertEquals(10_000_000, $lines['1121']->credit);
    }

    // ─── TC2: Phân bổ nhiều hóa đơn → JE nhiều dòng ─────────────────────────

    public function test_allocate_multiple_invoices_creates_multiline_je(): void
    {
        $tx   = $this->makeDebitTx(15_000_000);
        $inv1 = $this->makePurchaseInvoice(10_000_000, 'HD-NCC-001');
        $inv2 = $this->makePurchaseInvoice(5_000_000, 'HD-NCC-002');

        $party = ['type' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name, 'code' => $this->supplier->code];
        $allocations = [
            ['type' => 'purchase_invoice', 'id' => $inv1->id, 'amount' => 10_000_000, 'account_code' => '3311'],
            ['type' => 'purchase_invoice', 'id' => $inv2->id, 'amount' => 5_000_000,  'account_code' => '3311'],
        ];

        $je = $this->svc->allocate($tx, $party, $allocations);

        // 1 dòng bank + 2 dòng đối ứng = 3 dòng
        $this->assertCount(3, $je->lines);

        // Tổng Debit = Tổng Credit = 15M
        $totalDebit  = $je->lines->sum('debit');
        $totalCredit = $je->lines->sum('credit');
        $this->assertEquals(15_000_000, $totalDebit);
        $this->assertEquals(15_000_000, $totalCredit);

        // 2 bản ghi allocation được tạo
        $this->assertCount(2, BankTransactionAllocation::where('bank_transaction_id', $tx->id)->get());
    }

    // ─── TC3: Phân bổ một phần → match_status = partially_matched ────────────

    public function test_partial_allocation_sets_partially_matched_status(): void
    {
        $tx  = $this->makeDebitTx(10_000_000);
        $inv = $this->makePurchaseInvoice(10_000_000);

        $party = ['type' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name, 'code' => $this->supplier->code];
        $this->svc->allocate($tx, $party, [[
            'type'         => 'purchase_invoice',
            'id'           => $inv->id,
            'amount'       => 6_000_000, // chỉ thanh toán một phần tx (6/10M)
            'account_code' => '3311',
        ]]);

        $tx->refresh();
        $this->assertSame(BankTransactionMatchStatus::PartiallyMatched, $tx->match_status);
    }

    // ─── TC4: Phân bổ đầy đủ → match_status = posted ─────────────────────────

    public function test_full_allocation_sets_posted_status(): void
    {
        $tx  = $this->makeDebitTx(10_000_000);
        $inv = $this->makePurchaseInvoice(10_000_000);

        $party = ['type' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name, 'code' => $this->supplier->code];
        $this->svc->allocate($tx, $party, [[
            'type'         => 'purchase_invoice',
            'id'           => $inv->id,
            'amount'       => 10_000_000,
            'account_code' => '3311',
        ]]);

        $tx->refresh();
        $this->assertSame(BankTransactionMatchStatus::Posted, $tx->match_status);
        $this->assertSame(BankTransactionStatus::Reconciled, $tx->status);
        $this->assertNotNull($tx->reconciled_at);
    }

    // ─── TC5: Hủy phân bổ thủ công → đảo JE + reset status ──────────────────

    public function test_cancel_manual_allocation_reverses_je_and_resets_status(): void
    {
        $tx  = $this->makeDebitTx(10_000_000);
        $inv = $this->makePurchaseInvoice(10_000_000);

        $party = ['type' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name, 'code' => $this->supplier->code];
        $je = $this->svc->allocate($tx, $party, [[
            'type' => 'purchase_invoice', 'id' => $inv->id,
            'amount' => 10_000_000, 'account_code' => '3311',
        ]]);

        $this->svc->cancelAllocation($tx, 'Test hủy');

        // JE gốc phải là reversed
        $je->refresh();
        $this->assertSame('reversed', $je->status);

        // Bản ghi allocation bị cancelled
        $alloc = BankTransactionAllocation::where('bank_transaction_id', $tx->id)->first();
        $this->assertSame('cancelled', $alloc->status);

        // Giao dịch quay về unmatched
        $tx->refresh();
        $this->assertSame(BankTransactionMatchStatus::Unmatched, $tx->match_status);
        $this->assertSame(BankTransactionStatus::Pending, $tx->status);
        $this->assertNull($tx->journal_entry_id);
    }

    // ─── TC6: Hủy auto-match posted → đảo JE trực tiếp ──────────────────────

    public function test_cancel_auto_match_allocation_reverses_je(): void
    {
        $tx = $this->makeDebitTx(10_000_000);

        // Giả lập auto-match: tx có journal_entry_id nhưng không có allocation records
        $accounting = app(\App\Services\AccountingService::class);
        $je = $accounting->post(
            description: 'Auto-match JE',
            date: \Carbon\Carbon::parse('2026-06-15'),
            lines: [
                ['account' => '3311', 'debit' => 10_000_000, 'credit' => 0],
                ['account' => '1121', 'debit' => 0, 'credit' => 10_000_000],
            ],
        );

        $tx->update([
            'journal_entry_id'   => $je->id,
            'match_status'       => BankTransactionMatchStatus::Posted,
            'matched_party_type' => 'supplier',
            'matched_party_id'   => $this->supplier->id,
            'status'             => BankTransactionStatus::Reconciled,
            'reconciled_at'      => now(),
        ]);

        $this->svc->cancelAllocation($tx, 'Hủy auto-match');

        $je->refresh();
        $this->assertSame('reversed', $je->status);

        $tx->refresh();
        $this->assertSame(BankTransactionMatchStatus::Unmatched, $tx->match_status);
        $this->assertSame(BankTransactionStatus::Pending, $tx->status);
        $this->assertNull($tx->journal_entry_id);
    }

    // ─── TC7: Phân bổ lại khi đã có → ném exception ──────────────────────────

    public function test_double_allocate_throws_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/đã có phân bổ/i');

        $tx  = $this->makeDebitTx(10_000_000);
        $inv = $this->makePurchaseInvoice(10_000_000);

        $party = ['type' => 'supplier', 'id' => $this->supplier->id, 'name' => $this->supplier->name, 'code' => $this->supplier->code];
        $allocation = [['type' => 'purchase_invoice', 'id' => $inv->id, 'amount' => 10_000_000, 'account_code' => '3311']];

        $this->svc->allocate($tx, $party, $allocation);
        $this->svc->allocate($tx, $party, $allocation); // lần 2 → throw
    }

    // ─── TC8: Tự động nhận diện NCC từ số TK ngân hàng đối ứng ──────────────

    public function test_party_auto_lookup_from_supplier_bank_account(): void
    {
        SupplierBankAccount::create([
            'supplier_id'               => $this->supplier->id,
            'bank_name'                 => 'ACB',
            'account_number'            => '9988776655',
            'normalized_account_number' => '9988776655',
            'account_name'              => $this->supplier->name,
            'is_primary'                => true,
            'is_active'                 => true,
        ]);

        // Giao dịch tiền ra (debit) với counterpart_account khớp NCC
        $tx = BankTransaction::create([
            'bank_account_id'    => $this->bankAccount->id,
            'transaction_date'   => '2026-06-15',
            'description'        => 'Thanh toán NCC',
            'debit'              => 10_000_000,
            'credit'             => 0,
            'running_balance'    => 0,
            'counterpart_account' => '9988776655',
            'status'             => BankTransactionStatus::Pending,
            'match_status'       => BankTransactionMatchStatus::Unmatched,
            'created_by'         => $this->user->id,
        ]);

        $data = $this->svc->getReconcileData($tx);

        $this->assertNotNull($data['party']);
        $this->assertSame('supplier', $data['party']['type']);
        $this->assertSame($this->supplier->id, $data['party']['id']);
        $this->assertSame(95, $data['party']['confidence_score']);
    }

    // ─── TC8b: Tự động nhận diện KH từ số TK ngân hàng đối ứng ─────────────

    public function test_party_auto_lookup_from_customer_bank_account(): void
    {
        CustomerBankAccount::create([
            'customer_id'               => $this->customer->id,
            'bank_name'                 => 'Techcombank',
            'account_number'            => '1122334455',
            'normalized_account_number' => '1122334455',
            'account_name'              => $this->customer->name,
            'is_primary'                => true,
            'is_active'                 => true,
        ]);

        // Giao dịch tiền vào (credit) với counterpart_account khớp KH
        $tx = BankTransaction::create([
            'bank_account_id'     => $this->bankAccount->id,
            'transaction_date'    => '2026-06-15',
            'description'         => 'Thu tiền KH',
            'debit'               => 0,
            'credit'              => 5_000_000,
            'running_balance'     => 0,
            'counterpart_account' => '1122334455',
            'status'              => BankTransactionStatus::Pending,
            'match_status'        => BankTransactionMatchStatus::Unmatched,
            'created_by'          => $this->user->id,
        ]);

        $data = $this->svc->getReconcileData($tx);

        $this->assertNotNull($data['party']);
        $this->assertSame('customer', $data['party']['type']);
        $this->assertSame($this->customer->id, $data['party']['id']);
    }
}
