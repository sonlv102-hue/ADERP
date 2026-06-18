<?php

namespace Tests\Feature;

use App\Enums\SmallToolStatus;
use App\Models\AccountCode;
use App\Models\AccountingSetting;
use App\Models\SmallTool;
use App\Models\SmallToolAllocation;
use App\Models\SmallToolCategory;
use App\Models\SmallToolIssue;
use App\Models\SmallToolIssueItem;
use App\Models\SmallToolReceipt;
use App\Models\SmallToolReceiptItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SmallToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmallToolServiceTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Warehouse    $warehouse;
    private SmallToolCategory $category;
    private SmallToolService  $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user     = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::create(['name' => 'Kho Test', 'address' => 'HN', 'manager_id' => $this->user->id, 'is_active' => true]);
        $this->category  = SmallToolCategory::create(['code' => 'DC', 'name' => 'Dụng cụ văn phòng']);
        $this->svc       = app(SmallToolService::class);

        $this->seedAccounts();
    }

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1531', 'name' => 'Công cụ dụng cụ',       'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true, 'parent_code' => null],
            ['code' => '2422', 'name' => 'Chi phí trả trước CCDC', 'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true, 'parent_code' => null],
            ['code' => '6422', 'name' => 'Chi phí quản lý',        'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true, 'parent_code' => null],
            ['code' => '3311', 'name' => 'Phải trả NCC',           'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true, 'parent_code' => null],
            ['code' => '1331', 'name' => 'VAT được khấu trừ',      'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true, 'parent_code' => null],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], $a);
        }

        $settings = [
            ['key' => 'small_tool_stock_account',    'value' => '1531'],
            ['key' => 'small_tool_pending_account',  'value' => '2422'],
            ['key' => 'small_tool_expense_account',  'value' => '6422'],
            ['key' => 'payable_account',             'value' => '3311'],
            ['key' => 'vat_input_account',           'value' => '1331'],
        ];
        foreach ($settings as $s) {
            AccountingSetting::firstOrCreate(['key' => $s['key']], array_merge($s, ['label' => $s['key']]));
        }
    }

    private function makeTool(array $attrs = []): SmallTool
    {
        return SmallTool::create(array_merge([
            'code'                 => SmallTool::generateCode(),
            'name'                 => 'Máy tính cầm tay',
            'category_id'          => $this->category->id,
            'unit'                 => 'Cái',
            'quantity'             => 2,
            'original_cost'        => 1_000_000,
            'vat_amount'           => 100_000,
            'total_cost'           => 1_100_000,
            'acquisition_type'     => 'stock',
            'recognition_method'   => 'immediate',
            'payment_type'         => 'payable',
            'stock_account_code'   => '1531',
            'pending_account_code' => '2422',
            'expense_account_code' => '6422',
            'payable_account_code' => '3311',
            'status'               => SmallToolStatus::Draft,
            'created_by'           => $this->user->id,
        ], $attrs));
    }

    // --- Receipt flow ---

    public function test_confirm_receipt_posts_journal_and_sets_in_stock(): void
    {
        $receipt = SmallToolReceipt::create([
            'code'         => 'CCNK-0001',
            'receipt_date' => now()->toDateString(),
            'payment_type' => 'payable',
            'warehouse_id' => $this->warehouse->id,
            'status'       => 'draft',
            'created_by'   => $this->user->id,
            'subtotal'     => 2_000_000,
            'vat_amount'   => 200_000,
            'total_amount' => 2_200_000,
        ]);

        $tool = $this->makeTool(['status' => SmallToolStatus::Draft]);

        SmallToolReceiptItem::create([
            'small_tool_receipt_id' => $receipt->id,
            'small_tool_id'         => $tool->id,
            'quantity'              => 2,
            'unit_price'            => 1_000_000,
            'vat_amount'            => 200_000,
            'total_amount'          => 2_200_000,
        ]);

        $this->svc->confirmReceipt($receipt);

        $receipt->refresh();
        $tool->refresh();

        $this->assertEquals('confirmed', $receipt->status);
        $this->assertEquals(SmallToolStatus::InStock, $tool->status);
        $this->assertNotNull($receipt->journal_entry_id);
    }

    public function test_cancel_confirmed_receipt_reverses_journal_and_resets_draft(): void
    {
        $receipt = SmallToolReceipt::create([
            'code'         => 'CCNK-0001',
            'receipt_date' => now()->toDateString(),
            'payment_type' => 'payable',
            'warehouse_id' => $this->warehouse->id,
            'status'       => 'draft',
            'created_by'   => $this->user->id,
            'subtotal'     => 1_000_000,
            'vat_amount'   => 100_000,
            'total_amount' => 1_100_000,
        ]);
        $tool = $this->makeTool(['status' => SmallToolStatus::Draft]);
        SmallToolReceiptItem::create([
            'small_tool_receipt_id' => $receipt->id,
            'small_tool_id'         => $tool->id,
            'quantity'              => 2,
            'unit_price'            => 500_000,
            'vat_amount'            => 100_000,
            'total_amount'          => 1_100_000,
        ]);

        $this->svc->confirmReceipt($receipt);
        $this->svc->recallReceipt($receipt->fresh());

        $this->assertEquals('cancelled', $receipt->fresh()->status);
        $this->assertEquals(SmallToolStatus::Cancelled, $tool->fresh()->status);
    }

    // --- Direct use flow ---

    public function test_confirm_direct_tool_immediate_posts_journal(): void
    {
        $tool = $this->makeTool([
            'acquisition_type'   => 'direct',
            'recognition_method' => 'immediate',
            'status'             => SmallToolStatus::Draft,
        ]);

        $this->svc->confirmDirectTool($tool);

        $tool->refresh();
        $this->assertEquals(SmallToolStatus::InUse, $tool->status);
        $this->assertNotNull($tool->acquisition_journal_entry_id);
    }

    public function test_confirm_direct_tool_allocation_builds_schedule(): void
    {
        $tool = $this->makeTool([
            'acquisition_type'     => 'direct',
            'recognition_method'   => 'allocation',
            'allocation_periods'   => 6,
            'allocation_start_date'=> now()->format('Y-m'),
            'status'               => SmallToolStatus::Draft,
        ]);

        $this->svc->confirmDirectTool($tool);

        $tool->refresh();
        $this->assertEquals(SmallToolStatus::Allocating, $tool->status);
        $this->assertCount(6, $tool->allocations);
    }

    // --- Issue flow ---

    public function test_confirm_issue_immediate_removes_from_stock(): void
    {
        $tool = $this->makeTool(['status' => SmallToolStatus::InStock]);
        $issue = SmallToolIssue::create([
            'code'                   => 'CCXD-0001',
            'issue_date'             => now()->toDateString(),
            'recognition_method'     => 'immediate',
            'expense_account_code'   => '6422',
            'status'                 => 'draft',
            'created_by'             => $this->user->id,
            'total_amount'           => 1_000_000,
        ]);
        SmallToolIssueItem::create([
            'small_tool_issue_id' => $issue->id,
            'small_tool_id'       => $tool->id,
            'quantity'            => 1,
            'amount'              => 1_000_000,
        ]);

        $this->svc->confirmIssue($issue);

        $tool->refresh();
        $this->assertEquals(SmallToolStatus::InUse, $tool->status);
        $this->assertNotNull($tool->issue_journal_entry_id);
    }

    public function test_confirm_issue_allocation_sets_allocating_and_builds_schedule(): void
    {
        $tool = $this->makeTool([
            'status'             => SmallToolStatus::InStock,
            'recognition_method' => 'allocation',
            'allocation_periods' => 3,
        ]);
        $issue = SmallToolIssue::create([
            'code'                   => 'CCXD-0001',
            'issue_date'             => now()->toDateString(),
            'recognition_method'     => 'allocation',
            'allocation_periods'     => 3,
            'allocation_start_date'  => now()->format('Y-m'),
            'expense_account_code'   => '6422',
            'status'                 => 'draft',
            'created_by'             => $this->user->id,
            'total_amount'           => 900_000,
        ]);
        SmallToolIssueItem::create([
            'small_tool_issue_id' => $issue->id,
            'small_tool_id'       => $tool->id,
            'quantity'            => 1,
            'amount'              => 900_000,
        ]);

        $this->svc->confirmIssue($issue);

        $tool->refresh();
        $this->assertEquals(SmallToolStatus::Allocating, $tool->status);
        $this->assertCount(3, $tool->allocations);
    }

    // --- Disposal flow ---

    public function test_approve_disposal_cancels_pending_allocs_and_sets_status(): void
    {
        $tool = $this->makeTool([
            'status'             => SmallToolStatus::Allocating,
            'recognition_method' => 'allocation',
            'allocation_periods' => 6,
            'total_allocated'    => 200_000,
        ]);
        SmallToolAllocation::create([
            'small_tool_id'      => $tool->id,
            'period'             => now()->format('Y-m'),
            'period_start'       => now()->startOfMonth()->format('Y-m-d'),
            'period_end'         => now()->endOfMonth()->format('Y-m-d'),
            'amount'             => 100_000,
            'accumulated_before' => 200_000,
            'remaining_after'    => 700_000,
            'debit_account'      => '6422',
            'credit_account'     => '2422',
            'status'             => 'pending',
        ]);

        $disposal = \App\Models\SmallToolDisposal::create([
            'code'                  => 'CCXL-0001',
            'small_tool_id'         => $tool->id,
            'disposal_type'         => 'broken',
            'disposal_date'         => now()->toDateString(),
            'reason'                => 'Hỏng',
            'expense_account_code'  => '6422',
            'net_value_snapshot'    => 800_000,
            'journal_entry_ids'     => [],
            'status'                => 'draft',
            'created_by'            => $this->user->id,
        ]);

        $this->svc->approveDisposal($disposal);

        $tool->refresh();
        $this->assertEquals(SmallToolStatus::Broken, $tool->status);
        $this->assertEquals(0, $tool->allocations()->where('status', 'pending')->count());
    }
}
