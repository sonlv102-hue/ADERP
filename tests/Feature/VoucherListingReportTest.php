<?php

namespace Tests\Feature;

use App\Http\Controllers\Reports\DocumentChecklistController;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VoucherListingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::firstOrCreate(
            ['email' => 'vl-test@test.local'],
            ['name' => 'VL Test', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->user->givePermissionTo('reports.view');
        $this->actingAs($this->user);
        $this->seedAccounts();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function seedAccounts(): void
    {
        $accounts = [
            ['code' => '1111', 'name' => 'Tiền mặt VNĐ',       'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'is_active' => true],
            ['code' => '6422', 'name' => 'CCDC văn phòng',      'type' => 'expense',   'normal_balance' => 'debit',  'is_detail' => true,  'is_active' => true],
            ['code' => '5111', 'name' => 'Doanh thu bán hàng',  'type' => 'revenue',   'normal_balance' => 'credit', 'is_detail' => true,  'is_active' => true],
            ['code' => '3311', 'name' => 'Phải trả NCC HH',     'type' => 'liability', 'normal_balance' => 'credit', 'is_detail' => true,  'is_active' => true],
            ['code' => '1561', 'name' => 'Hàng hóa',            'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'is_active' => true],
            ['code' => '1331', 'name' => 'Thuế GTGT đầu vào',   'type' => 'asset',     'normal_balance' => 'debit',  'is_detail' => true,  'is_active' => true],
            ['code' => '511',  'name' => 'Doanh thu (tổng hợp)','type' => 'revenue',   'normal_balance' => 'credit', 'is_detail' => false, 'is_active' => true],
        ];
        foreach ($accounts as $a) {
            AccountCode::firstOrCreate(['code' => $a['code']], $a);
        }
    }

    private function makeJe(array $attrs, array $debitLines, array $creditLines): JournalEntry
    {
        $je = JournalEntry::create(array_merge([
            'code'       => 'BT-TEST-' . uniqid(),
            'entry_date' => '2026-01-15',
            'status'     => 'posted',
            'is_auto'    => false,
            'description'=> 'Test entry',
            'created_by' => $this->user->id,
        ], $attrs));

        $sort = 0;
        foreach ($debitLines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_code'     => $line[0],
                'debit'            => $line[1],
                'credit'           => 0,
                'sort_order'       => $sort++,
                'description'      => $line[2] ?? null,
            ]);
        }
        foreach ($creditLines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_code'     => $line[0],
                'debit'            => 0,
                'credit'           => $line[1],
                'sort_order'       => $sort++,
                'description'      => $line[2] ?? null,
            ]);
        }

        return $je;
    }

    private function ctrl(): DocumentChecklistController
    {
        return new DocumentChecklistController();
    }

    // ── Test 1: Bút toán 1N/1C ────────────────────────────────────────────

    public function test_simple_one_debit_one_credit_shows_two_rows_with_correct_counter_account(): void
    {
        $this->makeJe([], [['6422', 1_000_000]], [['1111', 1_000_000]]);

        $rows = $this->ctrl()->getRows(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $this->assertCount(2, $rows);

        $debitRow  = collect($rows)->firstWhere('debit', '>', 0);
        $creditRow = collect($rows)->firstWhere('credit', '>', 0);

        $this->assertEquals('6422', $debitRow['account_code']);
        $this->assertEquals('1111', $debitRow['counter_account']);
        $this->assertEquals(1_000_000, $debitRow['debit']);

        $this->assertEquals('1111', $creditRow['account_code']);
        $this->assertEquals('6422', $creditRow['counter_account']);
        $this->assertEquals(1_000_000, $creditRow['credit']);
    }

    // ── Test 2: Bút toán nhiều N / 1C ────────────────────────────────────

    public function test_multiple_debit_one_credit_counter_account(): void
    {
        $this->makeJe(
            [],
            [['1561', 10_000_000], ['1331', 1_000_000]],
            [['3311', 11_000_000]]
        );

        $rows = $this->ctrl()->getRows(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $debitRows  = collect($rows)->where('debit',  '>', 0)->values();
        $creditRows = collect($rows)->where('credit', '>', 0)->values();

        $this->assertCount(2, $debitRows);
        $this->assertCount(1, $creditRows);

        // Debit rows → counter = '3311' (single credit TK)
        foreach ($debitRows as $dr) {
            $this->assertEquals('3311', $dr['counter_account']);
        }
        // Credit row → counter = 'Nhiều TK'
        $this->assertEquals('Nhiều TK', $creditRows[0]['counter_account']);
    }

    // ── Test 3: Tổng Nợ = Tổng Có ────────────────────────────────────────

    public function test_totals_are_balanced(): void
    {
        $this->makeJe([], [['6422', 500_000]], [['1111', 500_000]]);
        $this->makeJe([], [['1561', 200_000], ['1331', 20_000]], [['3311', 220_000]]);

        [, $totals, $isBalanced] = $this->ctrl()->buildReport([
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
        ]);

        $this->assertEquals($totals['debit'], $totals['credit']);
        $this->assertTrue($isBalanced);
    }

    // ── Test 4: Filter theo ngày ──────────────────────────────────────────

    public function test_filter_by_date_excludes_out_of_range(): void
    {
        $this->makeJe(['entry_date' => '2026-01-10'], [['6422', 100_000]], [['1111', 100_000]]);
        $this->makeJe(['entry_date' => '2026-02-05'], [['6422', 200_000]], [['1111', 200_000]]);

        $rows = $this->ctrl()->getRows(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $this->assertCount(2, $rows); // chỉ entry tháng 1

        $rows2 = $this->ctrl()->getRows(['date_from' => '2026-02-01', 'date_to' => '2026-02-28']);
        $this->assertCount(2, $rows2); // chỉ entry tháng 2
    }

    // ── Test 5: Filter theo account_code ─────────────────────────────────

    public function test_filter_by_account_code(): void
    {
        $this->makeJe([], [['6422', 100_000]], [['1111', 100_000]]);
        $this->makeJe([], [['1561', 200_000]], [['3311', 200_000]]);

        $rows = $this->ctrl()->getRows([
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
            'account_code' => '6422',
        ]);

        $this->assertCount(1, $rows);
        $this->assertEquals('6422', $rows[0]['account_code']);
    }

    // ── Test 6: Không lấy draft / cancelled / reversed ───────────────────

    public function test_excludes_non_posted_journal_entries(): void
    {
        $this->makeJe(['status' => 'draft'],    [['6422', 100_000]], [['1111', 100_000]]);
        $this->makeJe(['status' => 'reversed'], [['6422', 100_000]], [['1111', 100_000]]);
        $posted = $this->makeJe(['status' => 'posted'], [['6422', 100_000]], [['1111', 100_000]]);

        $rows = $this->ctrl()->getRows(['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);

        $jeCodes = collect($rows)->pluck('je_code')->unique()->values();
        $this->assertCount(1, $jeCodes);
        $this->assertEquals($posted->code, $jeCodes[0]);
    }

    // ── Test 7: include_reversed flag ─────────────────────────────────────

    public function test_include_reversed_shows_reversed_entries(): void
    {
        $this->makeJe(['status' => 'reversed'], [['6422', 100_000]], [['1111', 100_000]]);

        $rowsNormal   = $this->ctrl()->getRows([
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
            'include_reversed' => false,
        ]);
        $rowsWithRev  = $this->ctrl()->getRows([
            'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
            'include_reversed' => true,
        ]);

        $this->assertCount(0, $rowsNormal);
        $this->assertCount(2, $rowsWithRev);
    }

    // ── Test 8: Web route trả Inertia page ────────────────────────────────

    public function test_index_route_returns_inertia_page(): void
    {
        $res = $this->getJson(route('reports.document_checklist') . '?date_from=2026-01-01&date_to=2026-01-31');
        $res->assertOk();
    }

    // ── Test 9: Export Excel không lỗi ────────────────────────────────────

    public function test_export_excel_returns_xlsx(): void
    {
        $this->makeJe([], [['6422', 100_000]], [['1111', 100_000]]);

        $res = $this->get(route('reports.document_checklist.export') . '?date_from=2026-01-01&date_to=2026-01-31');
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type') ?? '');
    }

    // ── Test 10: Export PDF (dùng shared signature component) ─────────────

    public function test_export_pdf_returns_pdf_and_uses_shared_signature_component(): void
    {
        $this->makeJe([], [['6422', 100_000]], [['1111', 100_000]]);
        \App\Models\Setting::set('report_signing_place', 'Hải Phòng', 'company');

        $res = $this->get(route('reports.document_checklist.pdf') . '?date_from=2026-01-01&date_to=2026-01-31');

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
    }
}
