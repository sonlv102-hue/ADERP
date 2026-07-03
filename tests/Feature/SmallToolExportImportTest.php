<?php

namespace Tests\Feature;

use App\Imports\SmallToolImport;
use App\Models\SmallTool;
use App\Models\SmallToolCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: exportExcel trả về file xlsx đúng content-type
 * TC2: exportPdf trả về file pdf đúng content-type
 * TC3: importTemplate trả về file xlsx đúng content-type
 * TC4: SmallToolImport — dòng hợp lệ được parse đúng, mã FK không tìm thấy -> warning (không lỗi)
 * TC5: SmallToolImport — thiếu name -> error, không parse
 * TC6: SmallToolImport — original_cost âm/không phải số -> error
 * TC7: SmallToolImport — recognition_method=allocation thiếu allocation_periods -> error
 * TC8: importPreview + importConfirm end-to-end qua CSV thật -> tạo đúng CCDC trạng thái draft
 * TC9: importConfirm không có session -> error, không tạo gì
 * TC10: Thiếu quyền ccdc.view/ccdc.manage -> 403
 */
class SmallToolExportImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SmallToolCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ccdc.view', 'ccdc.manage', 'accounting.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->syncRoles([$adminRole]);
        $this->actingAs($this->user);

        $this->category = SmallToolCategory::create(['code' => 'DC', 'name' => 'Dụng cụ']);
    }

    public function test_export_excel_returns_xlsx(): void
    {
        SmallTool::create(['code' => 'CCDC-0001', 'name' => 'Test', 'original_cost' => 100000, 'status' => 'draft']);

        $response = $this->get(route('accounting.small-tools.export-excel'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_pdf_returns_pdf(): void
    {
        SmallTool::create(['code' => 'CCDC-0001', 'name' => 'Test', 'original_cost' => 100000, 'status' => 'draft']);

        $response = $this->get(route('accounting.small-tools.export-pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_import_template_returns_xlsx(): void
    {
        $response = $this->get(route('accounting.small-tools.import.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_class_parses_valid_row_and_warns_on_missing_fk(): void
    {
        $import = new SmallToolImport(
            SmallToolCategory::all(),
            new Collection(),
            new Collection(),
            new Collection(),
            new Collection(),
        );

        $import->collection(new Collection([
            new Collection([
                'name' => 'Máy in', 'category_code' => 'DC', 'unit' => 'cái', 'quantity' => 2,
                'original_cost' => 1000000, 'vat_amount' => 100000,
                'acquisition_type' => 'stock', 'recognition_method' => 'immediate',
                'employee_code' => 'NV-999',
            ]),
        ]));

        $this->assertCount(1, $import->parsedTools);
        $this->assertCount(0, $import->errors);
        $this->assertSame('Máy in', $import->parsedTools[0]['name']);
        $this->assertSame($this->category->id, $import->parsedTools[0]['category_id']);
        $this->assertNull($import->parsedTools[0]['responsible_employee_id']);
        $this->assertCount(1, $import->warnings);
        $this->assertSame('employee_code', $import->warnings[0]['field']);
    }

    public function test_import_class_errors_on_missing_name(): void
    {
        $import = new SmallToolImport(new Collection(), new Collection(), new Collection(), new Collection(), new Collection());

        $import->collection(new Collection([
            new Collection(['name' => '', 'original_cost' => 100000]),
        ]));

        $this->assertCount(0, $import->parsedTools);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('Tên CCDC bị trống', $import->errors[0]['message']);
    }

    public function test_import_class_errors_on_invalid_original_cost(): void
    {
        $import = new SmallToolImport(new Collection(), new Collection(), new Collection(), new Collection(), new Collection());

        $import->collection(new Collection([
            new Collection(['name' => 'Test', 'original_cost' => -5]),
        ]));

        $this->assertCount(0, $import->parsedTools);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('Nguyên giá', $import->errors[0]['message']);
    }

    public function test_import_class_errors_on_negative_vat_amount(): void
    {
        $import = new SmallToolImport(new Collection(), new Collection(), new Collection(), new Collection(), new Collection());

        $import->collection(new Collection([
            new Collection(['name' => 'Test', 'original_cost' => 100000, 'vat_amount' => -5]),
        ]));

        $this->assertCount(0, $import->parsedTools);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('VAT', $import->errors[0]['message']);
    }

    public function test_import_class_warns_and_defaults_on_invalid_quantity(): void
    {
        $import = new SmallToolImport(new Collection(), new Collection(), new Collection(), new Collection(), new Collection());

        $import->collection(new Collection([
            new Collection(['name' => 'Test', 'original_cost' => 100000, 'quantity' => '1O']),
        ]));

        $this->assertCount(1, $import->parsedTools);
        $this->assertSame(1, $import->parsedTools[0]['quantity']);
        $this->assertCount(1, $import->warnings);
        $this->assertSame('quantity', $import->warnings[0]['field']);
    }

    public function test_import_class_requires_allocation_periods_when_allocation_method(): void
    {
        $import = new SmallToolImport(new Collection(), new Collection(), new Collection(), new Collection(), new Collection());

        $import->collection(new Collection([
            new Collection(['name' => 'Test', 'original_cost' => 100000, 'recognition_method' => 'allocation']),
        ]));

        $this->assertCount(0, $import->parsedTools);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('Số kỳ phân bổ', $import->errors[0]['message']);
    }

    public function test_import_preview_then_confirm_creates_draft_tools(): void
    {
        $csv = "name,category_code,unit,quantity,original_cost,vat_amount,acquisition_type,recognition_method,allocation_periods,department,notes\n"
             . "May dem tien,DC,cai,1,5000000,400000,direct,allocation,24,Ke toan,Ghi chu test\n"
             . "May tinh,DC,cai,3,300000,0,stock,immediate,,Kinh doanh,\n";

        $file = UploadedFile::fake()->createWithContent('ccdc.csv', $csv);

        $previewResponse = $this->post(route('accounting.small-tools.import.preview'), ['file' => $file]);
        $previewResponse->assertOk();

        $this->assertCount(2, session('ccdc_import'));

        $confirmResponse = $this->post(route('accounting.small-tools.import.confirm'));
        $confirmResponse->assertRedirect(route('accounting.small-tools.index'));

        $this->assertDatabaseCount('small_tools', 2);
        $this->assertDatabaseHas('small_tools', [
            'name'               => 'May dem tien',
            'status'             => 'draft',
            'category_id'        => $this->category->id,
            'recognition_method' => 'allocation',
            'allocation_periods' => 24,
        ]);
        $this->assertDatabaseHas('small_tools', [
            'name'   => 'May tinh',
            'status' => 'draft',
        ]);
        $this->assertNull(session('ccdc_import'));
    }

    public function test_import_confirm_without_session_shows_error(): void
    {
        $response = $this->post(route('accounting.small-tools.import.confirm'));

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('small_tools', 0);
    }

    public function test_export_forbidden_without_ccdc_view(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['accounting.view']);

        $response = $this->get(route('accounting.small-tools.export-excel'));

        $response->assertForbidden();
    }

    public function test_export_pdf_forbidden_without_ccdc_view(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['accounting.view']);

        $response = $this->get(route('accounting.small-tools.export-pdf'));

        $response->assertForbidden();
    }

    public function test_import_preview_forbidden_without_ccdc_manage(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['ccdc.view', 'accounting.view']);

        $file = UploadedFile::fake()->createWithContent('ccdc.csv', "name,original_cost\nTest,100000\n");
        $response = $this->post(route('accounting.small-tools.import.preview'), ['file' => $file]);

        $response->assertForbidden();
    }

    public function test_import_confirm_forbidden_without_ccdc_manage(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['ccdc.view', 'accounting.view']);

        $response = $this->post(route('accounting.small-tools.import.confirm'));

        $response->assertForbidden();
    }

    public function test_import_forbidden_without_ccdc_manage(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['ccdc.view', 'accounting.view']);

        $response = $this->get(route('accounting.small-tools.import.template'));

        $response->assertForbidden();
    }
}
