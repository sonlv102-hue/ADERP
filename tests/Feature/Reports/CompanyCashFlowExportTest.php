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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Phase 1.1 — Xuất Excel Dòng tiền tài khoản công ty.
 * Test 1-2: authorization (unauth/no-export-perm/export-perm/admin).
 * Test 3: filter consistency (Excel KPI/count == CompanyCashFlowReportService::summary()).
 * Test 4: internal transfer consolidated vs per-account, giống web report.
 * Test 5: giao dịch chưa phân loại vẫn xuất đủ (02, 06, nhóm "Chưa xác định").
 * Test 6: party group theo (party_type, party_id) — 2 party trùng tên không bị gộp.
 * Test 7: empty-filter regression (bug filter-null vừa fix ở Phase 1 hardening).
 * Test 8: workbook thật — đủ 6 sheet, đúng tên, header, numeric cell.
 * Test 9: Excel formula injection sanitize.
 * Test 10: RBAC seeder policy — director theo convention export sẵn có, role khác vẫn bị chặn.
 */
class CompanyCashFlowExportTest extends TestCase
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
        $this->report = app(CompanyCashFlowReportService::class);
        $this->seed(\Database\Seeders\CashFlowCategorySeeder::class);
    }

    private function account(string $name = 'VCB', float $opening = 0): BankAccount
    {
        return BankAccount::create([
            'name' => $name, 'bank_name' => $name, 'account_number' => (string) rand(100000, 999999),
            'opening_balance' => $opening, 'is_active' => true,
        ]);
    }

    private function tx(BankAccount $acc, string $date, float $debit = 0, float $credit = 0, array $extra = []): BankTransaction
    {
        return BankTransaction::create(array_merge([
            'bank_account_id' => $acc->id, 'transaction_date' => $date,
            'description' => 'GD test', 'debit' => $debit, 'credit' => $credit,
        ], $extra));
    }

    /** 2 lớp RBAC (conventions.md): 'reports.view' coarse + 'reports.bank_cashflow.export' granular. */
    private function roleWithPermissions(string $roleCode, array $codes): Role
    {
        $role = Role::create(['code' => $roleCode, 'name' => $roleCode, 'is_system' => false]);
        foreach ($codes as $code) {
            $permission = Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => 'reports']);
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function classify(BankTransaction $t, array $data): void
    {
        app(CashFlowClassificationService::class)->update($t, $data);
    }

    private function loadWorkbook(\Illuminate\Testing\TestResponse $response): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        return IOFactory::load($response->getFile()->getPathname());
    }

    // ─── Test 1: Authorization ──────────────────────────────────────────────

    public function test_export_rejects_unauthenticated(): void
    {
        $this->getJson(route('reports.company-cashflow.export'))->assertUnauthorized();
    }

    public function test_export_rejects_user_with_view_but_not_export(): void
    {
        $role = $this->roleWithPermissions('viewer_no_export', ['reports.view', 'reports.bank_cashflow.view']);
        $this->actingAs($this->userWithRole($role));

        $this->get(route('reports.company-cashflow.export'))->assertForbidden();
    }

    public function test_export_allows_user_with_export_permission(): void
    {
        $role = $this->roleWithPermissions('accounting_export', ['reports.view', 'reports.bank_cashflow.export']);
        $this->actingAs($this->userWithRole($role));
        $acc = $this->account();
        $this->tx($acc, '2026-09-05', credit: 1_000_000);

        $response = $this->get(route('reports.company-cashflow.export', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $response->assertOk();
        $this->assertStringEndsWith('.xlsx', $response->headers->get('content-disposition'));
    }

    public function test_export_allows_admin(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $this->tx($acc, '2026-09-05', credit: 1_000_000);

        $this->get(route('reports.company-cashflow.export', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk();
    }

    // ─── Test 2: Filter consistency (spec §24) ──────────────────────────────

    public function test_excel_kpi_matches_summary_service(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-EXP1', 'name' => 'Cong ty ABC', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $t1 = $this->tx($acc, '2026-09-05', credit: 5_000_000);
        $this->classify($t1, ['party_type' => 'customer', 'party_id' => $customer->id, 'cash_flow_category_id' => $category->id]);
        $t2 = $this->tx($acc, '2026-09-10', debit: 2_000_000);

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $summary = $this->report->summary($filters);

        $response = $this->get(route('reports.company-cashflow.export', $filters));
        $response->assertOk();
        $wb = $this->loadWorkbook($response);

        $overview = $wb->getSheetByName('01_Tong_quan');
        $rows = $overview->toArray(null, true, false, true);
        $kpi = collect($rows)->mapWithKeys(fn ($r) => [$r['A'] => $r['B']])->filter(fn ($v, $k) => is_string($k));

        $this->assertEqualsWithDelta((float) $summary['inflow'], (float) $kpi['Tổng tiền vào'], 0.01);
        $this->assertEqualsWithDelta((float) $summary['outflow'], (float) $kpi['Tổng tiền ra'], 0.01);
        $this->assertEqualsWithDelta((float) $summary['net_cash_flow'], (float) $kpi['Dòng tiền thuần'], 0.01);

        $txSheet = $wb->getSheetByName('02_Giao_dich');
        $this->assertEquals(2, $txSheet->getHighestRow() - 1); // trừ header row
    }

    /**
     * Library quirk phát hiện qua audit: Maatwebsite Excel mặc định so sánh `==` với null khi
     * ghi cell -> giá trị 0.0 THẬT (không phải "chưa có số liệu") bị bỏ trống thay vì hiện "0".
     * Test dùng account KHÔNG có giao dịch tiền ra nào -> outflow phải là cell numeric "0",
     * không phải cell rỗng (kiểm tra bằng isFormula()/getDataType(), không chỉ (float) cast
     * vì (float) null cũng ra 0.0 và sẽ che mất bug).
     */
    public function test_zero_kpi_value_renders_as_real_zero_not_blank_cell(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $this->tx($acc, '2026-09-05', credit: 1_000_000); // chỉ có tiền vào, outflow phải = 0

        $response = $this->get(route('reports.company-cashflow.export', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $wb = $this->loadWorkbook($response);
        $overview = $wb->getSheetByName('01_Tong_quan');

        $rows = $overview->toArray(null, true, false, true);
        $outflowRowNum = null;
        foreach ($rows as $rowNum => $r) {
            if ($r['A'] === 'Tổng tiền ra') {
                $outflowRowNum = $rowNum;
                break;
            }
        }
        $this->assertNotNull($outflowRowNum, 'Không tìm thấy dòng "Tổng tiền ra" trong sheet 01');

        $cell = $overview->getCell("B{$outflowRowNum}");
        $this->assertNotNull($cell->getValue(), 'Cell KPI = 0 thật bị ghi thành cell rỗng (Maatwebsite loose-null-comparison)');
        $this->assertSame(0.0, (float) $cell->getValue());
    }

    // ─── Test 3: Internal transfer (spec §25) ───────────────────────────────

    public function test_internal_transfer_excluded_consolidated_but_shown_per_account(): void
    {
        $this->actingAs($this->admin);
        $vcb = $this->account('VCB');
        $bidv = $this->account('BIDV');
        $internalCategory = CashFlowCategory::where('code', 'out_internal_transfer')->first();
        $internalCategoryIn = CashFlowCategory::where('code', 'in_internal_transfer')->first();

        $out = $this->tx($vcb, '2026-09-05', debit: 300_000_000);
        $in = $this->tx($bidv, '2026-09-05', credit: 300_000_000);
        $this->classify($out, ['cash_flow_category_id' => $internalCategory->id]);
        $this->classify($in, ['cash_flow_category_id' => $internalCategoryIn->id]);

        $consolidatedFilters = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $consolidatedSummary = $this->report->summary($consolidatedFilters);
        $this->assertEquals(0.0, $consolidatedSummary['outflow']);
        $this->assertEquals(0.0, $consolidatedSummary['inflow']);

        $wbConsolidated = $this->loadWorkbook($this->get(route('reports.company-cashflow.export', $consolidatedFilters)));
        $kpi = collect($wbConsolidated->getSheetByName('01_Tong_quan')->toArray(null, true, false, true))
            ->mapWithKeys(fn ($r) => [$r['A'] => $r['B']])->filter(fn ($v, $k) => is_string($k));
        $this->assertEqualsWithDelta(0.0, (float) $kpi['Tổng tiền ra'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $kpi['Tổng tiền vào'], 0.01);

        // Per-account VCB: giao dịch thực -300tr vẫn phải xuất đúng.
        $perAccountFilters = ['from' => '2026-09-01', 'to' => '2026-09-30', 'bank_account_id' => $vcb->id];
        $wbVcb = $this->loadWorkbook($this->get(route('reports.company-cashflow.export', $perAccountFilters)));
        $kpiVcb = collect($wbVcb->getSheetByName('01_Tong_quan')->toArray(null, true, false, true))
            ->mapWithKeys(fn ($r) => [$r['A'] => $r['B']])->filter(fn ($v, $k) => is_string($k));
        $this->assertEqualsWithDelta(300_000_000, (float) $kpiVcb['Tổng tiền ra'], 0.01);

        // Sheet 04 (Tiền ra theo danh mục) khi consolidated PHẢI khớp KPI = 0, không được
        // vẫn cộng giao dịch internal transfer vào TỔNG CỘNG (review finding: trước fix,
        // categoryBreakdownForExport() dùng byCategory() thô sẽ ra 300tr, lệch KPI sheet 01).
        $outflowRows = $wbConsolidated->getSheetByName('04_Tien_ra')->toArray();
        $totalRow = end($outflowRows);
        $this->assertEqualsWithDelta(0.0, (float) $totalRow[4], 0.01, 'TỔNG CỘNG sheet 04 phải khớp KPI = 0 khi internal transfer bị loại (consolidated)');
    }

    // ─── Test 4: Uncategorized (spec §26) ────────────────────────────────────

    public function test_uncategorized_transaction_appears_everywhere_expected(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $t = $this->tx($acc, '2026-09-05', credit: 1_500_000, extra: ['description' => 'GD chua phan loai']);

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $response = $this->get(route('reports.company-cashflow.export', $filters));
        $wb = $this->loadWorkbook($response);

        $txRows = $wb->getSheetByName('02_Giao_dich')->toArray();
        $this->assertTrue(collect($txRows)->contains(fn ($r) => in_array('GD chua phan loai', $r, true)));

        $kpi = collect($wb->getSheetByName('01_Tong_quan')->toArray(null, true, false, true))
            ->mapWithKeys(fn ($r) => [$r['A'] => $r['B']])->filter(fn ($v, $k) => is_string($k));
        $this->assertEqualsWithDelta(1_500_000, (float) $kpi['Tổng tiền vào'], 0.01);
        $this->assertEqualsWithDelta(1_500_000, (float) $kpi['Tiền vào chưa xác định nguồn'], 0.01);

        $unreconciledRows = $wb->getSheetByName('06_Chua_doi_soat')->toArray();
        $this->assertTrue(collect($unreconciledRows)->contains(fn ($r) => in_array('GD chua phan loai', $r, true)));

        $inflowRows = $wb->getSheetByName('03_Tien_vao')->toArray();
        $this->assertTrue(collect($inflowRows)->contains(fn ($r) => in_array('Chưa xác định', $r, true)));
    }

    // ─── Test 5: Party grouping theo type+id (spec §27) ─────────────────────

    public function test_two_parties_same_name_different_type_not_merged(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-DUP', 'name' => 'Cong ty ABC', 'is_active' => true]);
        $supplier = \App\Models\Supplier::create(['code' => 'NCC-DUP', 'name' => 'Cong ty ABC', 'is_active' => true]);

        $t1 = $this->tx($acc, '2026-09-05', credit: 1_000_000);
        $this->classify($t1, ['party_type' => 'customer', 'party_id' => $customer->id]);
        $t2 = $this->tx($acc, '2026-09-06', debit: 700_000);
        $this->classify($t2, ['party_type' => 'supplier', 'party_id' => $supplier->id]);

        $filters = ['from' => '2026-09-01', 'to' => '2026-09-30'];
        $wb = $this->loadWorkbook($this->get(route('reports.company-cashflow.export', $filters)));
        $partyRows = collect($wb->getSheetByName('05_Doi_tuong')->toArray())
            ->filter(fn ($r) => $r[3] === 'Cong ty ABC');

        $this->assertCount(2, $partyRows, 'Hai đối tượng trùng tên khác loại phải là 2 dòng riêng biệt');
    }

    // ─── Test 6: Empty filter regression (spec §28) ─────────────────────────

    public function test_export_with_empty_category_and_project_filter_does_not_hide_classified_transactions(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $customer = Customer::create(['code' => 'KH-EMP', 'name' => 'KH Empty Filter', 'is_active' => true]);
        $category = CashFlowCategory::where('code', 'in_customer_payment')->first();

        $classified = $this->tx($acc, '2026-09-05', credit: 2_000_000);
        $this->classify($classified, ['party_type' => 'customer', 'party_id' => $customer->id, 'cash_flow_category_id' => $category->id]);
        $unclassified = $this->tx($acc, '2026-09-06', credit: 500_000);

        // Payload y hệt Index.vue applyFilters() gửi khi user KHÔNG chọn category/project —
        // ConvertEmptyStringsToNull biến '' thành null trước khi tới controller.
        $response = $this->get(route('reports.company-cashflow.export', [
            'from' => '2026-09-01', 'to' => '2026-09-30',
            'cash_flow_category_id' => '', 'project_id' => '',
        ]));
        $response->assertOk();
        $wb = $this->loadWorkbook($response);

        $this->assertEquals(2, $wb->getSheetByName('02_Giao_dich')->getHighestRow() - 1);

        $kpi = collect($wb->getSheetByName('01_Tong_quan')->toArray(null, true, false, true))
            ->mapWithKeys(fn ($r) => [$r['A'] => $r['B']])->filter(fn ($v, $k) => is_string($k));
        $this->assertEqualsWithDelta(2_500_000, (float) $kpi['Tổng tiền vào'], 0.01);
    }

    // ─── Test 7: Workbook thật (spec §33) ────────────────────────────────────

    public function test_workbook_has_exactly_6_sheets_with_correct_names(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $this->tx($acc, '2026-09-05', credit: 1_000_000);

        $response = $this->get(route('reports.company-cashflow.export', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $response->assertOk();

        $expectedFilename = 'Bao_cao_dong_tien_20260901_20260930.xlsx';
        $this->assertStringContainsString($expectedFilename, $response->headers->get('content-disposition'));

        $wb = $this->loadWorkbook($response);
        $this->assertEquals(
            ['01_Tong_quan', '02_Giao_dich', '03_Tien_vao', '04_Tien_ra', '05_Doi_tuong', '06_Chua_doi_soat'],
            $wb->getSheetNames()
        );

        $txSheet = $wb->getSheetByName('02_Giao_dich');
        $this->assertEquals('STT', $txSheet->getCell('A1')->getValue());
        $this->assertEquals('Tiền vào', $txSheet->getCell('H1')->getValue());
        // Cột Tiền vào (H2) phải là numeric thật, không phải string định dạng sẵn.
        $this->assertIsNumeric($txSheet->getCell('H2')->getValue());
    }

    // ─── Test 8: Formula injection (spec §22) ────────────────────────────────

    public function test_bank_description_starting_with_equals_is_sanitized(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $t = $this->tx($acc, '2026-09-05', credit: 1_000_000, extra: [
            'description' => '=SUM(A1:A10)',
            'counterpart_name' => '+CMD|/c calc',
        ]);
        $this->classify($t, ['cash_flow_note' => '@import evil', 'party_name' => null]);

        $response = $this->get(route('reports.company-cashflow.export', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $wb = $this->loadWorkbook($response);
        $txRows = $wb->getSheetByName('02_Giao_dich')->toArray();
        $dataRow = $txRows[1];

        // description ở cột S (index 18), ghi chú cột T (19) — xem headings() TransactionsSheet.
        $this->assertStringStartsWith("'=", (string) $dataRow[18]);
        $this->assertStringStartsWith("'@", (string) $dataRow[19]);
    }

    /** Review finding: formula bắt đầu bằng tab/CR trước "=" cũng phải bị chặn, không chỉ index 0. */
    public function test_formula_with_leading_whitespace_is_still_sanitized(): void
    {
        $this->actingAs($this->admin);
        $acc = $this->account();
        $this->tx($acc, '2026-09-05', credit: 1_000_000, extra: ['description' => "\t=cmd|'/c calc'!A0"]);

        $response = $this->get(route('reports.company-cashflow.export', ['from' => '2026-09-01', 'to' => '2026-09-30']));
        $wb = $this->loadWorkbook($response);
        $dataRow = $wb->getSheetByName('02_Giao_dich')->toArray()[1];

        $this->assertStringStartsWith("'", (string) $dataRow[18]);
    }

    /** Review finding: OverviewSheet (sheet 01) trước fix không sanitize tên tài khoản/dự án/người xuất. */
    public function test_overview_sheet_sanitizes_bank_account_name(): void
    {
        $this->actingAs($this->admin);
        $acc = BankAccount::create([
            'name' => '=cmd|\'/c calc\'!A0', 'bank_name' => 'VCB', 'account_number' => (string) rand(100000, 999999),
            'opening_balance' => 0, 'is_active' => true,
        ]);
        $this->tx($acc, '2026-09-05', credit: 1_000_000);

        $response = $this->get(route('reports.company-cashflow.export', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'bank_account_id' => $acc->id,
        ]));
        $wb = $this->loadWorkbook($response);
        $bankAccountCell = (string) $wb->getSheetByName('01_Tong_quan')->getCell('B5')->getValue();

        $this->assertStringStartsWith("'=", $bankAccountCell);
    }

    // ─── Test 10: RBAC seeder policy (quyết định pre-deploy audit 2026-09-15) ──────
    /**
     * reports.bank_cashflow.export đi theo đúng convention export sẵn có của hệ thống —
     * RolePermissionSeeder có rule catch-all gán mọi permission action=export cho role
     * director (đã áp dụng từ trước cho 9 permission export khác: sales.orders.export,
     * reports.financial.export, v.v.) — không tạo ngoại lệ RBAC riêng cho module này.
     * Test khóa lại: role rõ ràng KHÔNG được cấp (không nằm trong accountingCodes lẫn
     * catch-all director) vẫn phải bị chặn.
     */
    public function test_export_permission_follows_existing_role_seeder_policy(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        foreach (['admin', 'super_admin', 'accounting', 'director'] as $code) {
            $role = Role::where('code', $code)->first();
            $this->assertNotNull($role, "Role {$code} không tồn tại sau RolePermissionSeeder");
            $this->assertTrue(
                $role->permissions->contains('code', 'reports.bank_cashflow.export'),
                "{$code} phải có quyền export theo convention hiện tại của hệ thống"
            );
        }

        foreach (['hr', 'sales', 'warehouse', 'project', 'read_only'] as $code) {
            $role = Role::where('code', $code)->first();
            $this->assertNotNull($role, "Role {$code} không tồn tại sau RolePermissionSeeder");
            $this->assertFalse(
                $role->permissions->contains('code', 'reports.bank_cashflow.export'),
                "{$code} không được có quyền export dòng tiền tài khoản công ty"
            );
        }
    }
}
