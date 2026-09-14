<?php

namespace Tests\Feature\Reports;

use App\Enums\PurchaseContractStatus;
use App\Models\BankAccount;
use App\Models\CashFlowCategory;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\PurchaseContract;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyCashFlowReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Smoke test hiệu năng (spec §13) — 10.000 bank_transactions, kiểm tra không có N+1
 * và index cơ bản hoạt động. Chạy trên SQLite in-memory (môi trường test chuẩn của
 * project) — Chưa kiểm chứng trực tiếp trên Postgres thật, đặc tính EXPLAIN/planner
 * có thể khác (Postgres dùng index composite hiệu quả hơn SQLite cho tập lớn).
 */
class CompanyCashFlowPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const ROW_COUNT = 10_000;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_active' => true]);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_system' => true]);
        $this->admin->roles()->sync([$adminRole->id]);
        $this->actingAs($this->admin);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function seedTransactions(): array
    {
        $accountIds = [];
        for ($i = 0; $i < 5; $i++) {
            $accountIds[] = BankAccount::create([
                'name' => "TK{$i}", 'bank_name' => 'VCB', 'account_number' => "ACC{$i}",
                'opening_balance' => 0, 'is_active' => true,
            ])->id;
        }
        // Loại 2 category "chuyển tiền nội bộ" khỏi pool ngẫu nhiên — test này đo hiệu năng
        // thuần túy, không phải test logic loại trừ internal-transfer (đã có test riêng).
        $categoryIds = CashFlowCategory::whereNotIn('code', CashFlowCategory::INTERNAL_TRANSFER_CODES)->pluck('id')->all();

        $now = now();
        $rows = [];
        for ($i = 0; $i < self::ROW_COUNT; $i++) {
            $date = now()->startOfYear()->addDays($i % 270)->toDateString();
            $isCredit = $i % 2 === 0;
            $rows[] = [
                'bank_account_id' => $accountIds[$i % 5],
                'transaction_date' => $date,
                'description' => "GD perf {$i}",
                'debit' => $isCredit ? 0 : 1_000_000,
                'credit' => $isCredit ? 1_000_000 : 0,
                // 30% đã phân loại — mô phỏng dữ liệu thực tế trộn lẫn đã/chưa đối soát.
                'cash_flow_category_id' => $i % 10 < 3 ? $categoryIds[$i % count($categoryIds)] : null,
                'party_type' => $i % 10 < 3 ? 'customer' : null,
                'party_id' => $i % 10 < 3 ? ($i % 50) + 1 : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                DB::table('bank_transactions')->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table('bank_transactions')->insert($rows);
        }

        return $accountIds;
    }

    public function test_report_handles_10000_transactions_without_n_plus_1(): void
    {
        $accountIds = $this->seedTransactions();
        $service = app(CompanyCashFlowReportService::class);
        $filters = ['from' => now()->startOfYear()->toDateString(), 'to' => now()->endOfYear()->toDateString()];

        DB::enableQueryLog();
        $start = microtime(true);
        $summary = $service->summary($filters);
        $page = $service->transactions($filters, 20);
        foreach ($page->items() as $row) {
            // Chạm vào các relation eager-load để phát hiện N+1 nếu with() thiếu.
            $row->bankAccount?->name;
            $row->cashFlowCategory?->name;
            $row->project?->name;
        }
        $byCategory = $service->byCategory($filters);
        $elapsed = microtime(true) - $start;
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(self::ROW_COUNT, (int) round(($summary['inflow'] + $summary['outflow']) / 1_000_000));
        $this->assertCount(20, $page->items());
        $this->assertNotEmpty($byCategory);

        // Không có hard rule số query "đúng", nhưng phải KHÔNG scale theo per-row (N+1) —
        // trang 20 dòng + summary (2 query/account để tính đầu/cuối kỳ x 5 account) + byCategory
        // phải nằm trong một hằng số nhỏ, không phải hàng trăm.
        $this->assertLessThan(40, $queryCount, "Nghi ngờ N+1 — {$queryCount} query cho 1 lượt tải trang báo cáo");
        $this->assertLessThan(5.0, $elapsed, "Quá chậm với {$queryCount} query trong {$elapsed}s trên SQLite in-memory");
    }

    /**
     * Kiểm tra filter HẸP (1 account, 3 ngày trên 10k dòng/5 account ~ 0.15% bảng) dùng MỘT
     * index nào đó (không Seq Scan toàn bảng) — filter rộng (cả năm) không selective, Postgres
     * có thể hợp lý chọn Seq Scan (không phải bug, xem test khác). Driver-aware: `EXPLAIN QUERY
     * PLAN` là cú pháp SQLite-only, Postgres dùng `EXPLAIN` thường — trước đây hard-code cú pháp
     * SQLite khiến test luôn lỗi cú pháp trên Postgres (chưa từng verify trên Postgres thật).
     *
     * KHÔNG hard-assert đúng tên `bank_tx_account_date_idx`: chạy thật trên PostgreSQL 16.15 cho
     * thấy planner chọn index đơn `bt_account_id` (FK tự động trên bank_account_id, kèm Filter
     * transaction_date) — cost tương đương composite mới thêm ở quy mô dữ liệu này, KHÔNG phải
     * bug. Assert tên index cụ thể là "phụ thuộc format text dễ đổi giữa version" đúng như audit
     * pre-deploy cảnh báo tránh — chỉ assert có dùng Index Scan (không Seq Scan) là đủ ý nghĩa.
     */
    public function test_period_and_account_filter_uses_index(): void
    {
        $this->seedTransactions();

        $accountId = 1;
        $from = now()->startOfYear()->toDateString();
        $to = now()->startOfYear()->addDays(2)->toDateString();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Planner cần thống kê mới để ước lượng selectivity đúng — bảng vừa insert
            // hàng loạt trong cùng transaction test, chưa có autovacuum ANALYZE tự động.
            DB::statement('ANALYZE bank_transactions');
            $plan = DB::select(
                'EXPLAIN SELECT * FROM bank_transactions WHERE bank_account_id = ? AND transaction_date BETWEEN ? AND ?',
                [$accountId, $from, $to]
            );
            $planText = collect($plan)->pluck('QUERY PLAN')->implode(' | ');
        } elseif ($driver === 'sqlite') {
            $plan = DB::select(
                'EXPLAIN QUERY PLAN SELECT * FROM bank_transactions WHERE bank_account_id = ? AND transaction_date BETWEEN ? AND ?',
                [$accountId, $from, $to]
            );
            $planText = collect($plan)->pluck('detail')->implode(' | ');
        } else {
            $this->markTestSkipped("Chưa hỗ trợ kiểm tra EXPLAIN cho driver '{$driver}'");

            return;
        }

        $this->assertStringContainsStringIgnoringCase('index', $planText, "Filter hẹp (1 account, 3 ngày) nhưng không dùng index nào — plan: {$planText}");
        $this->assertStringNotContainsStringIgnoringCase('seq scan', $planText, "Filter hẹp (1 account, 3 ngày) trên 10k dòng lại Seq Scan toàn bảng — plan: {$planText}");
    }

    /**
     * Regression cho N+1 phát hiện qua pre-deploy audit: BankTransaction::contractLabel()
     * gọi Contract::find()/PurchaseContract::find() riêng từng dòng — CompanyCashFlowController
     * đã sửa để preload theo batch (2 whereIn thay vì N find()). 20 giao dịch, MỖI giao dịch
     * một contract KHÁC NHAU, trộn cả Sales Contract lẫn Purchase Contract — nếu implementation
     * cũ (contractLabel() gọi find() trong loop) còn sống, số query sẽ tăng tuyến tính theo số
     * dòng (~20 query thêm), test này phải fail; sau fix, số query không đổi theo N.
     */
    public function test_transactions_with_20_different_contracts_does_not_n_plus_1(): void
    {
        $account = BankAccount::create([
            'name' => 'TK contract test', 'bank_name' => 'VCB', 'account_number' => 'ACC-CL',
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $customer = Customer::create(['code' => 'KH-NPLUS1', 'name' => 'KH N+1', 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'NCC-NPLUS1', 'name' => 'NCC N+1', 'is_active' => true]);

        $rows = [];
        $now = now();
        for ($i = 0; $i < 20; $i++) {
            $isSales = $i % 2 === 0;
            if ($isSales) {
                $contract = Contract::create([
                    'code' => "HD-NPLUS-{$i}", 'customer_id' => $customer->id, 'title' => "HĐ N+1 test {$i}",
                    'status' => 'draft', 'created_by' => $this->admin->id,
                ]);
                $contractType = 'contract';
            } else {
                $contract = PurchaseContract::create([
                    'code' => "HD-MH-NPLUS-{$i}", 'supplier_id' => $supplier->id, 'title' => "HĐ mua N+1 test {$i}",
                    'value' => 1_000_000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
                    'status' => PurchaseContractStatus::Draft, 'created_by' => $this->admin->id,
                ]);
                $contractType = 'purchase_contract';
            }

            $rows[] = [
                'bank_account_id' => $account->id,
                'transaction_date' => now()->startOfYear()->addDays($i)->toDateString(),
                'description' => "GD contract {$i}",
                'debit' => 0,
                'credit' => 1_000_000,
                'contract_type' => $contractType,
                'contract_id' => $contract->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('bank_transactions')->insert($rows);

        $filters = ['from' => now()->startOfYear()->toDateString(), 'to' => now()->endOfYear()->toDateString()];

        // Đo đúng 1 lượt gọi HTTP thật (route + controller + transactionDto), không lẫn
        // query của bước seed dữ liệu ở trên.
        DB::enableQueryLog();
        $response = $this->getJson(route('reports.company-cashflow.transactions', $filters));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $labels = collect($response->json('data'))->pluck('contract_label')->filter();
        $this->assertCount(20, $labels, 'Mỗi trong 20 giao dịch phải có contract_label — thiếu nghĩa là preload map bị lệch contract_id/contract_type.');

        // Đo thực tế: implementation cũ (contractLabel() gọi find() trong loop) ra đúng
        // 35 query (baseline 15 + 20 find() riêng, +1/dòng — N+1 kinh điển); sau fix batch
        // preload còn 15, không đổi theo N. Ngưỡng 20 để có biên an toàn nhưng vẫn cách xa
        // 35 — nếu N+1 quay lại (dù chỉ 1 field khác lặp find() trong loop) sẽ bắt được ngay.
        $this->assertLessThan(20, $queryCount, "Nghi ngờ N+1 contractLabel() — {$queryCount} query cho 20 giao dịch (20 contract khác nhau)");
    }
}
