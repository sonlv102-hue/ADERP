<?php

namespace Tests\Feature\Reports;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\CashFlowCategory;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashFlowClassificationService;
use App\Services\CompanyCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Hardening pass — spec §8/§11: filter đối soát, pagination, IDOR/granular permission. */
class CompanyCashFlowFilterAndAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CompanyCashFlowReportService $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]);
        $this->actingAs($this->admin);
        $this->report = app(CompanyCashFlowReportService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function account(): BankAccount
    {
        return BankAccount::create(['name' => 'VCB', 'bank_name' => 'VCB', 'account_number' => (string) rand(10000, 99999), 'opening_balance' => 0, 'is_active' => true]);
    }

    private function tx(BankAccount $acc, string $date, float $debit = 0, float $credit = 0, array $extra = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'bank_account_id' => $acc->id, 'transaction_date' => $date,
            'description' => 'GD test', 'debit' => $debit, 'credit' => $credit,
        ], $extra));
    }

    /**
     * RBAC 2 lớp (conventions.md): vào được namespace /reports/* cần 'reports.view' (coarse)
     * TRƯỚC KHI xét granular 'reports.bank_cashflow.*' — role "viewer" test luôn cần cả 2,
     * $extraCodes là các quyền reconcile/transactions.view muốn cấp THÊM (mặc định không cấp).
     */
    private function viewerRole(string $roleCode, array $extraCodes = []): Role
    {
        $role = Role::create(['code' => $roleCode, 'name' => $roleCode, 'is_system' => false]);
        foreach (array_merge(['reports.view', 'reports.bank_cashflow.view'], $extraCodes) as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'reports']);
            $role->permissions()->attach($permission->id);
        }
        return $role;
    }

    /** §11 list item 11 — giao dịch chưa phân loại vẫn phải nằm trong tổng dòng tiền, không bị loại khỏi tổng. */
    public function test_uncategorized_transactions_still_counted_in_total_cashflow(): void
    {
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-F1', 'name' => 'KH F1', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $classified = $this->tx($acc, '2026-09-05', credit: 100_000_000);
        app(CashFlowClassificationService::class)->update($classified, [
            'party_type' => 'customer', 'party_id' => $customer->id, 'cash_flow_category_id' => $category->id,
        ]);
        $this->tx($acc, '2026-09-06', credit: 50_000_000); // chưa phân loại

        $summary = $this->report->summary(['from' => '2026-09-01', 'to' => '2026-09-30']);

        $this->assertSame(150_000_000.0, $summary['inflow'], 'Tổng tiền vào phải gồm CẢ giao dịch chưa phân loại');
        $this->assertSame(50_000_000.0, $summary['unclassified_inflow']);
    }

    /**
     * Regression — DTO transactions() JSON phải trả party_id/project_id (không chỉ tên),
     * nếu không ClassifyModal.vue không pre-fill được các RemoteSearchSelect khi mở lại
     * giao dịch ĐÃ phân loại -> lưu field khác (vd note) sẽ gửi party_id=null, bị service
     * NULL hoá mất đối tượng/dự án đã gán trước đó (data loss âm thầm trước khi có validate;
     * sau khi thêm validate ở CashFlowClassificationService thì lỗi CÓ hiện ra nhưng vẫn chặn
     * luôn việc sửa field khác nếu FE không gửi lại đúng party_id/project_id cũ).
     */
    public function test_transactions_json_exposes_party_id_and_project_id_for_reedit(): void
    {
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-F4', 'name' => 'KH F4', 'is_active' => true]);
        $t = $this->tx($acc, '2026-09-05', credit: 100);
        app(CashFlowClassificationService::class)->update($t, ['party_type' => 'customer', 'party_id' => $customer->id]);

        $response = $this->getJson(route('reports.company-cashflow.transactions', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $row = collect($response->json('data'))->firstWhere('id', $t->id);

        $this->assertSame($customer->id, $row['party_id']);
        $this->assertArrayHasKey('project_id', $row);
    }

    /**
     * REGRESSION — bug thật phát hiện qua browser E2E test (§8), KHÔNG lộ ra qua SQLite:
     * Index.vue luôn gửi TOÀN BỘ filterForm kể cả field chưa chọn (select mặc định
     * value=""). Trên Postgres, "" bị coi khác null -> baseQuery() build
     * `cash_flow_category_id = ''` trên cột bigint -> QueryException
     * "invalid input syntax for type bigint" (22P02). SQLite không strict kiểu nên test
     * này PHẢI assert trực tiếp trên SQL bindings (driver-agnostic) thay vì chỉ assertOk(),
     * nếu không sẽ pass giả trên SQLite dù bug vẫn còn trên Postgres thật.
     */
    public function test_empty_string_filters_from_real_form_do_not_leak_into_sql_bindings(): void
    {
        DB::enableQueryLog();

        $response = $this->get(route('reports.company-cashflow.index', [
            'from' => '', 'to' => '', 'bank_account_id' => '', 'cash_flow_category_id' => '',
            'party_type' => '', 'party_id' => '', 'project_id' => '', 'direction' => '',
            'search' => '', 'reconcile_status' => '',
        ]));
        $response->assertOk();

        foreach (DB::getQueryLog() as $q) {
            foreach ($q['bindings'] as $binding) {
                $this->assertNotSame('', $binding, "Query có binding rỗng — nguy cơ lỗi kiểu dữ liệu trên Postgres: {$q['query']}");
            }
        }
        DB::disableQueryLog();
    }

    /**
     * REGRESSION #2 — bug thật phát hiện qua pre-deploy audit E2E (browser thật): middleware
     * `ConvertEmptyStringsToNull` biến field CHƯA CHỌN (cash_flow_category_id="" — form luôn
     * gửi mọi field, kể cả field chưa chọn) thành PHP `null` TRƯỚC KHI tới controller —
     * `CompanyCashFlowController::filters()` bản cũ chỉ strip `''`, không strip `null`, nên
     * field null này lọt vào $filters -> baseQuery() hiểu nhầm thành lọc "CHƯA phân loại"
     * (whereNull) -> MỌI giao dịch ĐÃ phân loại biến mất khỏi report mỗi khi bấm "Lọc" mà
     * không chọn category cụ thể (tức là hầu như luôn luôn, vì mặc định không chọn category).
     * Test trước đó (test_empty_string_filters_...) chỉ guard lỗi 22P02, KHÔNG bắt được bug
     * này vì nó không throw exception — chỉ âm thầm trả thiếu dữ liệu.
     */
    public function test_classified_transactions_still_appear_when_category_filter_left_empty(): void
    {
        $acc = $this->account();
        $classified = $this->tx($acc, '2026-09-05', credit: 100);
        $category = CashFlowCategory::where('code', 'in_other')->first();
        app(CashFlowClassificationService::class)->update($classified, ['cash_flow_category_id' => $category->id]);
        $unclassified = $this->tx($acc, '2026-09-06', credit: 200);

        // Mô phỏng CHÍNH XÁC payload thật Index.vue::applyFilters() gửi khi user bấm "Lọc"
        // mà KHÔNG đụng vào dropdown category (mọi field khác cũng luôn có mặt, rỗng).
        $response = $this->getJson(route('reports.company-cashflow.transactions', [
            'from' => '2026-09-01', 'to' => '2026-09-30',
            'bank_account_id' => '', 'cash_flow_category_id' => '',
            'party_type' => '', 'party_id' => '', 'project_id' => '',
            'direction' => '', 'search' => '', 'reconcile_status' => '',
        ]));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($classified->id), 'Giao dịch ĐÃ phân loại phải vẫn xuất hiện khi filter category để trống (mặc định)');
        $this->assertTrue($ids->contains($unclassified->id));
        $this->assertCount(2, $ids);
    }

    /** Sentinel "-- Chưa xác định --" (string "null") vẫn phải lọc đúng CHỈ giao dịch chưa phân loại. */
    public function test_explicit_unclassified_sentinel_still_filters_correctly(): void
    {
        $acc = $this->account();
        $classified = $this->tx($acc, '2026-09-05', credit: 100);
        $category = CashFlowCategory::where('code', 'in_other')->first();
        app(CashFlowClassificationService::class)->update($classified, ['cash_flow_category_id' => $category->id]);
        $unclassified = $this->tx($acc, '2026-09-06', credit: 200);

        $response = $this->getJson(route('reports.company-cashflow.transactions', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'cash_flow_category_id' => 'null',
        ]));
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($unclassified->id));
        $this->assertFalse($ids->contains($classified->id), 'Sentinel "-- Chưa xác định --" phải LOẠI giao dịch đã phân loại');
        $this->assertCount(1, $ids);
    }

    /** reconcile_status filter phải khớp CHÍNH XÁC logic BankTransaction::reconcileStatus(). */
    public function test_reconcile_status_filter_unclassified(): void
    {
        $acc = $this->account();
        $unclassified = $this->tx($acc, '2026-09-05', credit: 10);
        $categorized = $this->tx($acc, '2026-09-05', credit: 20);
        $categorized->update(['cash_flow_category_id' => CashFlowCategory::where('code', 'in_other')->value('id')]);

        $rows = $this->report->transactions(['from' => '2026-09-01', 'to' => '2026-09-30', 'reconcile_status' => 'unclassified'], 50);

        $this->assertCount(1, $rows->items());
        $this->assertSame($unclassified->id, $rows->items()[0]->id);
    }

    public function test_reconcile_status_filter_completed(): void
    {
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-F2', 'name' => 'KH F2', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $complete = $this->tx($acc, '2026-09-05', credit: 10, extra: ['cash_voucher_id' => null]);
        app(CashFlowClassificationService::class)->update($complete, [
            'party_type' => 'customer', 'party_id' => $customer->id, 'cash_flow_category_id' => $category->id,
        ]);
        $complete->update(['contract_id' => 1]); // giả lập đã liên kết chứng từ (contract_id NOT NULL đủ để hasDocument=true)

        $categorizedOnly = $this->tx($acc, '2026-09-05', credit: 20);
        $categorizedOnly->update(['cash_flow_category_id' => $category->id]);

        $rows = $this->report->transactions(['from' => '2026-09-01', 'to' => '2026-09-30', 'reconcile_status' => 'completed'], 50);

        $this->assertCount(1, $rows->items());
        $this->assertSame($complete->id, $rows->items()[0]->id);
        $this->assertSame(\App\Enums\CashFlowReconcileStatus::Completed, $complete->fresh()->reconcileStatus());
    }

    /**
     * Cross-check SQL filter applyReconcileStatusFilter() với PHP BankTransaction::reconcileStatus()
     * trên TẤT CẢ trạng thái có thể đạt được — tránh lệch logic giữa 2 nơi định nghĩa cùng 1 khái niệm.
     */
    public function test_reconcile_status_filter_matches_computed_status_for_every_reachable_state(): void
    {
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-F3', 'name' => 'KH F3', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $unclassified = $this->tx($acc, '2026-09-05', credit: 1);

        $partyOnly = $this->tx($acc, '2026-09-05', credit: 2);
        app(CashFlowClassificationService::class)->update($partyOnly, ['party_type' => 'customer', 'party_id' => $customer->id]);

        $categorizedOnly = $this->tx($acc, '2026-09-05', credit: 3);
        $categorizedOnly->update(['cash_flow_category_id' => $category->id]);

        $documentOnly = $this->tx($acc, '2026-09-05', credit: 4);
        $documentOnly->update(['contract_id' => 1]);

        $completed = $this->tx($acc, '2026-09-05', credit: 5);
        app(CashFlowClassificationService::class)->update($completed, ['party_type' => 'customer', 'party_id' => $customer->id, 'cash_flow_category_id' => $category->id]);
        $completed->update(['contract_id' => 1]);

        // NeedsReview — phân loại "chuyển tiền nội bộ" nhưng CHƯA cặp đôi.
        $internal = \App\Models\InternalBankAccount::create(['name' => 'Cty', 'account_number' => 'X1', 'bank_name' => 'BIDV']);
        $unpaired = $this->tx($acc, '2026-09-05', debit: 6, extra: ['internal_account_id' => $internal->id]);
        $unpaired->update(['cash_flow_category_id' => CashFlowCategory::where('code', 'out_internal_transfer')->value('id')]);
        // Cũng gán thêm party+document để chứng minh needs_review VẪN ưu tiên cao hơn completed.
        $unpaired->update(['party_type' => 'customer', 'party_id' => $customer->id, 'contract_id' => 1]);

        $all = [$unclassified, $partyOnly, $categorizedOnly, $documentOnly, $completed, $unpaired];
        $statuses = ['unclassified', 'party_identified', 'categorized', 'document_linked', 'completed', 'needs_review'];

        foreach ($statuses as $status) {
            $expectedIds = collect($all)->filter(fn ($t) => $t->fresh()->reconcileStatus()->value === $status)->pluck('id')->sort()->values();
            $rows = $this->report->transactions(['from' => '2026-09-01', 'to' => '2026-09-30', 'reconcile_status' => $status], 50);
            $actualIds = collect($rows->items())->pluck('id')->sort()->values();

            $this->assertSame($expectedIds->all(), $actualIds->all(), "reconcile_status={$status} không khớp giữa SQL filter và PHP reconcileStatus()");
        }
    }

    /** §26 — pagination không được làm mất filter đang áp dụng. */
    public function test_pagination_preserves_filters(): void
    {
        $acc = $this->account();
        $other = $this->account();
        for ($i = 0; $i < 25; $i++) {
            $this->tx($acc, '2026-09-05', credit: 10 + $i);
        }
        $this->tx($other, '2026-09-05', credit: 999); // account khác — không được lọt vào

        // Phải test qua HTTP thật — withQueryString() đọc query string của request hiện tại,
        // gọi thẳng service trong PHPUnit không có request HTTP nên không phản ánh đúng hành vi.
        $response = $this->getJson(route('reports.company-cashflow.transactions', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'bank_account_id' => $acc->id,
        ]));
        $response->assertOk();
        $json = $response->json();

        $this->assertSame(25, $json['meta']['total'] ?? $json['total']);
        $nextPageUrl = $json['meta']['next_page_url'] ?? $json['next_page_url'];
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('bank_account_id='.$acc->id, $nextPageUrl);
    }

    /** §11 — granular permission: có quyền VIEW nhưng KHÔNG có quyền RECONCILE -> classify phải 403 backend. */
    public function test_view_only_user_cannot_classify(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $role = $this->viewerRole('cashflow_viewer');
        $viewer->roles()->sync([$role->id]);

        $acc = $this->account();
        $t = $this->tx($acc, '2026-09-05', credit: 100);

        // Có quyền xem trang.
        $this->actingAs($viewer)->get(route('reports.company-cashflow.index'))->assertOk();

        // Nhưng KHÔNG được phép classify — kể cả gọi thẳng endpoint (IDOR-style, không qua UI).
        $this->actingAs($viewer)
            ->post(route('reports.company-cashflow.classify', $t->id), ['cash_flow_note' => 'hack'])
            ->assertForbidden();

        $this->assertNull($t->fresh()->cash_flow_note);
    }

    /** Granular permission riêng cho JSON transactions endpoint (không dùng chung .view). */
    public function test_view_only_user_without_transactions_permission_gets_403_on_json_endpoint(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $role = $this->viewerRole('cashflow_viewer2');
        $viewer->roles()->sync([$role->id]);

        $this->actingAs($viewer)
            ->getJson(route('reports.company-cashflow.transactions'))
            ->assertForbidden();
    }

    /** confirm-pair cũng phải đòi quyền reconcile riêng, không chỉ view. */
    public function test_view_only_user_cannot_confirm_pair(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $role = $this->viewerRole('cashflow_viewer3');
        $viewer->roles()->sync([$role->id]);

        $this->actingAs($viewer)
            ->post(route('reports.company-cashflow.confirm-pair'), ['outgoing_id' => 1, 'incoming_id' => 2])
            ->assertForbidden();
    }
}
