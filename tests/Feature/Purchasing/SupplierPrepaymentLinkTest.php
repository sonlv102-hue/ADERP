<?php

namespace Tests\Feature\Purchasing;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\PurchaseContract;
use App\Models\PurchaseContractPaymentSchedule;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierOpeningAdvance;
use App\Models\User;
use App\Models\Warehouse;
use App\Enums\PurchaseContractStatus;
use App\Enums\PaymentScheduleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class SupplierPrepaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Supplier $supplier;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass permissions check if any
        Gate::before(fn ($user, $ability) => true);

        $this->user = User::factory()->create([
            'is_active' => true,
        ]);
        $this->actingAs($this->user);

        // Setup Accounting Period & Account Codes for prepayments (needed by SupplierAdvanceService)
        AccountingPeriod::create(['year' => 2026, 'month' => 7, 'status' => 'open']);
        
        foreach ([
            ['331',   'Phải trả NCC',            'liability', 'credit', null,    2, false],
            ['3311',  'Phải trả NCC chi tiết',    'liability', 'credit', '331',  3, true],
            ['331UT', 'Trả trước cho người bán',  'asset',     'debit',  '331',  4, true],
            ['1111',  'Tiền mặt VND',             'asset',     'debit',  null,   2, true],
            ['112',   'Tiền gửi ngân hàng',       'asset',     'debit',  null,   2, false],
            ['1121',  'Tiền gửi ngân hàng chi tiết','asset',    'debit',  '112',  3, true],
        ] as [$code, $name, $type, $nb, $parent, $level, $isDetail]) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name'           => $name,
                'type'           => $type,
                'normal_balance' => $nb,
                'parent_code'    => $parent,
                'level'          => $level,
                'is_detail'      => $isDetail,
                'is_active'      => true,
            ]);
        }

        $this->supplier = Supplier::create([
            'code' => 'NCC-TEST-01',
            'name' => 'Nhà Cung Cấp Test 01',
            'payable_account_code' => '3311',
        ]);

        $this->warehouse = Warehouse::create([
            'code'       => 'K-TEST-01',
            'name'       => 'Kho Test 01',
            'address'    => 'Hà Nội',
            'manager_id' => $this->user->id,
            'is_active'  => true,
        ]);
    }

    /**
     * Test tự động tạo prepayment khi thỏa mãn điều kiện
     * Ngày thanh toán dự kiến (due_date) < Ngày dự kiến nhận hàng (expected_date)
     */
    public function test_auto_creates_prepayment_when_due_date_is_before_expected_date(): void
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TEST-01',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-07-10',
            'expected_date'=> '2026-07-20', // PO expected date
            'created_by'   => $this->user->id,
        ]);

        $contract = PurchaseContract::create([
            'code' => 'HD-MH-TEST-01',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'title' => 'Hợp đồng mua test',
            'value' => 10000000,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
            'status' => PurchaseContractStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        // Tạo đợt thanh toán có due_date trước expected_date (2026-07-15 < 2026-07-20)
        $schedule = $contract->paymentSchedules()->create([
            'name'       => 'Đợt 1 - Tạm ứng 30%',
            'percentage' => 30,
            'amount'     => 3000000,
            'due_date'   => '2026-07-15',
            'status'     => PaymentScheduleStatus::Pending,
            'created_by' => $this->user->id,
        ]);

        // Kiểm tra prepayment tự động sinh ra
        $prepayment = SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
        
        $this->assertNotNull($prepayment);
        $this->assertEquals('prepayment', $prepayment->advance_type);
        $this->assertEquals($contract->id, $prepayment->purchase_contract_id);
        $this->assertEquals($po->id, $prepayment->purchase_order_id);
        $this->assertEquals($schedule->id, $prepayment->payment_schedule_id);
        $this->assertEquals(3000000, (float)$prepayment->amount);
        $this->assertEquals('unpaid', $prepayment->status);
    }

    /**
     * Test không tạo prepayment khi:
     * Ngày thanh toán dự kiến (due_date) >= Ngày dự kiến nhận hàng (expected_date)
     */
    public function test_does_not_create_prepayment_when_due_date_is_after_expected_date(): void
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TEST-02',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-07-10',
            'expected_date'=> '2026-07-20', // PO expected date
            'created_by'   => $this->user->id,
        ]);

        $contract = PurchaseContract::create([
            'code' => 'HD-MH-TEST-02',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'title' => 'Hợp đồng mua test 2',
            'value' => 10000000,
            'status' => PurchaseContractStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        // Tạo đợt thanh toán có due_date sau expected_date (2026-07-25 >= 2026-07-20)
        $schedule = $contract->paymentSchedules()->create([
            'name'       => 'Đợt 2 - Thanh toán 70%',
            'percentage' => 70,
            'amount'     => 7000000,
            'due_date'   => '2026-07-25',
            'status'     => PaymentScheduleStatus::Pending,
            'created_by' => $this->user->id,
        ]);

        $prepayment = SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
        $this->assertNull($prepayment);
    }

    /**
     * Test tự động cập nhật hoặc hủy/xóa mềm prepayment khi đổi hạn thanh toán
     */
    public function test_auto_updates_or_deletes_prepayment_on_schedule_changes(): void
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TEST-03',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-07-10',
            'expected_date'=> '2026-07-20',
            'created_by'   => $this->user->id,
        ]);

        $contract = PurchaseContract::create([
            'code' => 'HD-MH-TEST-03',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'title' => 'Hợp đồng mua test 3',
            'value' => 10000000,
            'status' => PurchaseContractStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        // 1. Tạo đợt thanh toán (due_date = 2026-07-15 < 2026-07-20) -> tạo prepayment
        $schedule = $contract->paymentSchedules()->create([
            'name'       => 'Đợt 1',
            'percentage' => 50,
            'amount'     => 5000000,
            'due_date'   => '2026-07-15',
            'status'     => PaymentScheduleStatus::Pending,
            'created_by' => $this->user->id,
        ]);

        $prepayment = SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
        $this->assertNotNull($prepayment);
        $this->assertEquals(5000000, (float)$prepayment->amount);

        // 2. Sửa số tiền (percentage = 40) -> prepayment cập nhật theo
        $schedule->update([
            'percentage' => 40,
            'amount'     => 4000000,
        ]);

        $prepayment->refresh();
        $this->assertEquals(4000000, (float)$prepayment->amount);

        // 3. Đổi due_date thành sau ngày dự kiến nhận hàng (2026-07-25 >= 2026-07-20) -> prepayment bị xóa mềm
        $schedule->update([
            'due_date' => '2026-07-25',
        ]);

        $prepaymentDeleted = SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
        $this->assertNull($prepaymentDeleted);
    }

    /**
     * Test không cho phép sửa/xóa đợt thanh toán nếu prepayment đã thanh toán/sử dụng thực tế
     */
    public function test_blocks_schedule_changes_if_prepayment_is_paid_or_used(): void
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TEST-04',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-07-10',
            'expected_date'=> '2026-07-20',
            'created_by'   => $this->user->id,
        ]);

        $contract = PurchaseContract::create([
            'code' => 'HD-MH-TEST-04',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'title' => 'Hợp đồng mua test 4',
            'value' => 10000000,
            'status' => PurchaseContractStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        $schedule = $contract->paymentSchedules()->create([
            'name'       => 'Đợt 1',
            'percentage' => 50,
            'amount'     => 5000000,
            'due_date'   => '2026-07-15',
            'status'     => PaymentScheduleStatus::Pending,
            'created_by' => $this->user->id,
        ]);

        $prepayment = SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
        $this->assertNotNull($prepayment);

        // Giả lập prepayment đã được đối trừ/sử dụng (remaining_amount < amount)
        $prepayment->update([
            'remaining_amount' => 4000000, // Đã đối trừ 1.000.000 đ
        ]);

        // Cố tình cập nhật đợt thanh toán -> phải quăng RuntimeException chặn lại
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Không thể cập nhật lịch thanh toán do khoản trả trước tương ứng đã phát sinh thanh toán hoặc đối trừ thực tế trên hệ thống.');
        
        $schedule->update([
            'amount' => 4500000,
        ]);
    }

    /**
     * Test hạch toán thanh toán khoản trả trước NCC từ unpaid -> open
     * Đồng thời kiểm tra xem CashVoucher và JournalEntry có được tạo tự động và liên kết chính xác không
     */
    public function test_confirms_prepayment_payment_and_creates_linked_journal_entries(): void
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TEST-05',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-07-10',
            'expected_date'=> '2026-07-20',
            'created_by'   => $this->user->id,
        ]);

        $contract = PurchaseContract::create([
            'code' => 'HD-MH-TEST-05',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'title' => 'Hợp đồng mua test 5',
            'value' => 10000000,
            'status' => PurchaseContractStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        $schedule = $contract->paymentSchedules()->create([
            'name'       => 'Đợt 1',
            'percentage' => 50,
            'amount'     => 5000000,
            'due_date'   => '2026-07-15',
            'status'     => PaymentScheduleStatus::Pending,
            'created_by' => $this->user->id,
        ]);

        $prepayment = SupplierOpeningAdvance::where('payment_schedule_id', $schedule->id)->first();
        $this->assertNotNull($prepayment);
        $this->assertEquals('unpaid', $prepayment->status);

        // Tạo quỹ cho việc chi tiền thanh toán
        $fund = \App\Models\Fund::create([
            'code'         => 'QUY-TEST-01',
            'name'         => 'Quỹ test 01',
            'type'         => 'bank',
            'account_code' => '1121',
            'is_active'    => true,
        ]);

        // Thực hiện thanh toán
        app(\App\Services\SupplierAdvanceService::class)->payPrepayment(
            $prepayment,
            $fund->id,
            'bank_transfer',
            '2026-07-15'
        );

        $prepayment->refresh();
        $this->assertEquals('open', $prepayment->status);
        $this->assertEquals('2026-07-15', $prepayment->opening_date->format('Y-m-d'));

        // Kiểm tra xem CashVoucher và JournalEntry có được tạo tự động không
        $voucher = \App\Models\CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
            ->where('reference_id', $prepayment->id)
            ->first();
        $this->assertNotNull($voucher);
        $this->assertEquals(\App\Enums\CashVoucherStatus::Confirmed, $voucher->status);

        $je = \App\Models\JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', $voucher->id)
            ->first();
        $this->assertNotNull($je);
        $this->assertEquals('posted', $je->status);

        // Kiểm tra xem các trường liên kết trên bút toán đã được điền chính xác chưa
        $this->assertEquals($this->supplier->id, $je->supplier_id);
        $this->assertEquals($contract->id, $je->purchase_contract_id);
        $this->assertEquals($po->id, $je->purchase_order_id);
        $this->assertEquals($prepayment->id, $je->supplier_prepayment_id);

        // Kiểm tra dòng bút toán Nợ 331UT / Có 1121
        $lines = $je->lines;
        $this->assertCount(2, $lines);
        
        $debitLine = $lines->where('debit', '>', 0)->first();
        $creditLine = $lines->where('credit', '>', 0)->first();

        $this->assertEquals('331UT', $debitLine->account_code);
        $this->assertEquals(5000000, (float)$debitLine->debit);
        $this->assertEquals('supplier', $debitLine->partner_type);
        $this->assertEquals($this->supplier->id, $debitLine->partner_id);

        $this->assertEquals('1121', $creditLine->account_code);
        $this->assertEquals(5000000, (float)$creditLine->credit);
    }

    /**
     * Test tạo thủ công khoản trả trước NCC có gắn Hợp đồng mua và Đơn mua hàng (không bắt buộc)
     */
    public function test_manual_prepayment_creation_with_links(): void
    {
        $po = PurchaseOrder::create([
            'code'         => 'MH-TEST-MANUAL-01',
            'supplier_id'  => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date'   => '2026-07-10',
            'expected_date'=> '2026-07-20',
            'created_by'   => $this->user->id,
        ]);

        $contract = PurchaseContract::create([
            'code' => 'HD-MH-MANUAL-01',
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'title' => 'Hợp đồng mua test thủ công',
            'value' => 10000000,
            'status' => PurchaseContractStatus::Draft,
            'created_by' => $this->user->id,
        ]);

        $response = $this->post(route('purchasing.supplier-advances.store'), [
            'supplier_id'          => $this->supplier->id,
            'advance_type'         => 'opening_balance',
            'opening_date'         => '2026-07-10',
            'amount'               => 2000000,
            'reference_no'         => 'MANUAL-REF-01',
            'purchase_contract_id' => $contract->id,
            'purchase_order_id'    => $po->id,
            'fiscal_year'          => 2026,
        ]);

        $response->assertRedirect();
        
        $advance = SupplierOpeningAdvance::where('reference_no', 'MANUAL-REF-01')->first();
        $this->assertNotNull($advance);
        $this->assertEquals($contract->id, $advance->purchase_contract_id);
        $this->assertEquals($po->id, $advance->purchase_order_id);
        $this->assertNull($advance->payment_schedule_id); // không sinh từ lịch thanh toán
    }

    /**
     * Test danh sách gợi ý ứng trước có thể đối trừ (available_advances)
     * sẽ ưu tiên xếp các khoản có cùng Đơn mua hàng (PO) hoặc Hợp đồng mua lên đầu
     */
    public function test_available_advances_sorting_prioritizes_matching_contract_and_order(): void
    {
        $po1 = PurchaseOrder::create([
            'code' => 'MH-SORT-01', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => '2026-07-10', 'expected_date' => '2026-07-20', 'created_by' => $this->user->id
        ]);
        $po2 = PurchaseOrder::create([
            'code' => 'MH-SORT-02', 'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id, 'order_date' => '2026-07-10', 'expected_date' => '2026-07-20', 'created_by' => $this->user->id
        ]);

        $contract1 = PurchaseContract::create([
            'code' => 'HD-SORT-01', 'supplier_id' => $this->supplier->id, 'purchase_order_id' => $po1->id, 'title' => 'Hợp đồng 1', 'value' => 5000000, 'status' => PurchaseContractStatus::Draft, 'created_by' => $this->user->id
        ]);

        // Tạo 3 khoản ứng trước:
        // 1. Advance 1: Không gắn gì
        $adv1 = SupplierOpeningAdvance::create([
            'supplier_id' => $this->supplier->id, 'advance_type' => 'opening_balance', 'opening_date' => '2026-07-10', 'amount' => 1000000, 'remaining_amount' => 1000000, 'currency' => 'VND', 'status' => 'open', 'created_by' => $this->user->id, 'fiscal_year' => 2026
        ]);
        // 2. Advance 2: Gắn PO 2
        $adv2 = SupplierOpeningAdvance::create([
            'supplier_id' => $this->supplier->id, 'advance_type' => 'opening_balance', 'opening_date' => '2026-07-11', 'amount' => 2000000, 'remaining_amount' => 2000000, 'currency' => 'VND', 'status' => 'open', 'created_by' => $this->user->id, 'fiscal_year' => 2026, 'purchase_order_id' => $po2->id
        ]);
        // 3. Advance 3: Gắn Contract 1
        $adv3 = SupplierOpeningAdvance::create([
            'supplier_id' => $this->supplier->id, 'advance_type' => 'opening_balance', 'opening_date' => '2026-07-12', 'amount' => 3000000, 'remaining_amount' => 3000000, 'currency' => 'VND', 'status' => 'open', 'created_by' => $this->user->id, 'fiscal_year' => 2026, 'purchase_contract_id' => $contract1->id
        ]);

        $service = app(\App\Services\SupplierAdvanceService::class);

        // Trường hợp 1: Tìm ứng trước ưu tiên cùng PO 2
        $res1 = $service->getAvailable($this->supplier->id, $po2->id, null);
        $this->assertEquals($adv2->id, $res1[0]->id); // Trùng PO 2 phải lên đầu

        // Trường hợp 2: Tìm ứng trước ưu tiên cùng Contract 1
        $res2 = $service->getAvailable($this->supplier->id, null, $contract1->id);
        $this->assertEquals($adv3->id, $res2[0]->id); // Trùng Contract 1 phải lên đầu

        // Trường hợp 3: Ưu tiên cả hai (PO 2 và Contract 1)
        $res3 = $service->getAvailable($this->supplier->id, $po2->id, $contract1->id);
        $this->assertEquals($adv2->id, $res3[0]->id); // Trùng PO (trọng số cao hơn) lên đầu
        $this->assertEquals($adv3->id, $res3[1]->id); // Trùng Contract lên thứ hai
        $this->assertEquals($adv1->id, $res3[2]->id); // Không trùng xuống cuối cùng
    }
}
