<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TC1: Export Excel lọc theo status=active
 * TC2: Export PDF 1 nhân viên có đủ mã/tên/phòng ban/chức vụ
 * TC3: Print view render không lỗi, không lỗi dấu tiếng Việt
 * TC4: Template import đủ 3 sheet + cột bắt buộc
 * TC5: Upload file hợp lệ tạo mới nhân viên
 * TC6: Mã nhân viên trùng trong file → lỗi
 * TC7: Mã nhân viên đã tồn tại, không bật update → lỗi
 * TC8: Thiếu trường bắt buộc (họ tên/phòng ban) → lỗi
 * TC9: File có lỗi → confirm không ghi DB (all-or-nothing)
 */
class EmployeeExportImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->syncRoles([$adminRole]);
        $this->actingAs($admin);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'code'       => Employee::generateCode(),
            'name'       => 'Nhân viên test',
            'department' => 'Kỹ thuật',
            'created_by' => auth()->id(),
        ], $overrides));
    }

    private function makeImportCsv(array $rows): UploadedFile
    {
        $lines = [implode(',', array_fill(0, 22, 'h'))]; // header dòng 1, nội dung không quan trọng
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => $v ?? '', $row));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'emp_import_') . '.csv';
        file_put_contents($tmp, implode("\n", $lines));

        return new UploadedFile($tmp, 'import.csv', 'text/csv', null, true);
    }

    private function previewRows(array $rows, bool $updateExisting = false)
    {
        return $this->post(route('admin.employees.import.preview'), [
            'file'            => $this->makeImportCsv($rows),
            'update_existing' => $updateExisting,
        ]);
    }

    private function confirm(string $previewId, bool $updateExisting = false)
    {
        return $this->post(route('admin.employees.import.confirm'), [
            'preview_id'      => $previewId,
            'update_existing' => $updateExisting,
        ]);
    }

    // ── TC1 ──────────────────────────────────────────────────────────────────

    public function test_export_excel_filters_by_status(): void
    {
        Excel::fake();
        $this->makeEmployee(['code' => 'NV-1001', 'name' => 'Active User', 'status' => 'active']);
        $this->makeEmployee(['code' => 'NV-1002', 'name' => 'Resigned User', 'status' => 'resigned']);

        $response = $this->get(route('admin.employees.export.excel', ['status' => 'active']));
        $response->assertStatus(200);

        Excel::assertDownloaded('Danh_sach_nhan_vien_' . Carbon::now()->format('Ymd') . '.xlsx', function ($export) {
            $codes = collect($export->array())->pluck(1)->filter()->values();
            return $codes->contains('NV-1001') && !$codes->contains('NV-1002');
        });
    }

    // ── TC2 ──────────────────────────────────────────────────────────────────

    public function test_export_pdf_for_employee(): void
    {
        $employee = $this->makeEmployee([
            'code' => 'NV-1003', 'name' => 'Nguyễn Văn Test',
            'department' => 'Kỹ thuật', 'position' => 'Trưởng phòng',
        ]);

        $response = $this->get(route('admin.employees.export.pdf', $employee));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');

        $html = view('pdf.employee-profile', [
            'employee' => ['model' => $employee->fresh(), 'attachments' => collect()],
        ])->render();

        $this->assertStringContainsString('NV-1003', $html);
        $this->assertStringContainsString('Nguyễn Văn Test', $html);
        $this->assertStringContainsString('Kỹ thuật', $html);
        $this->assertStringContainsString('Trưởng phòng', $html);
    }

    // ── TC3 ──────────────────────────────────────────────────────────────────

    public function test_print_profile_renders_without_error(): void
    {
        $employee = $this->makeEmployee(['code' => 'NV-1004', 'name' => 'Trần Thị Print']);

        $response = $this->get(route('admin.employees.print', $employee));
        $response->assertStatus(200);
        $response->assertSee('Trần Thị Print', false);
        $response->assertSee('Hồ sơ nhân viên', false);
    }

    // ── TC4 ──────────────────────────────────────────────────────────────────

    public function test_import_template_has_required_sheets(): void
    {
        Excel::fake();
        $response = $this->get(route('admin.employees.import.template'));
        $response->assertStatus(200);

        Excel::assertDownloaded('Mau_upload_nhan_vien.xlsx', function ($export) {
            $sheets = $export->sheets();
            $headings = $sheets[0]->headings();
            return count($sheets) === 3
                && $headings[0] === 'Mã nhân viên *'
                && $headings[10] === 'Phòng ban *';
        });
    }

    // ── TC5 ──────────────────────────────────────────────────────────────────

    public function test_import_valid_file_creates_employees(): void
    {
        $resp = $this->previewRows([
            ['NV-9001', 'Nguyen Van X', 'Nam', '01/01/1990', '', '', '', '0912345678', 'x@test.com', '', 'Ky thuat', 'NV', '01/01/2020', 'full_time', 'active', '10000000', '1000000', '', '', '', '', ''],
        ]);
        $resp->assertStatus(200);
        $data = $resp->json();
        $this->assertSame(0, $data['summary']['error_rows']);

        $confirmResp = $this->confirm($data['preview_id']);
        $confirmResp->assertStatus(200);
        $confirmData = $confirmResp->json();

        $this->assertTrue($confirmData['success']);
        $this->assertSame(1, $confirmData['created']);
        $this->assertDatabaseHas('employees', ['code' => 'NV-9001', 'name' => 'Nguyen Van X']);
    }

    // ── TC6 ──────────────────────────────────────────────────────────────────

    public function test_import_duplicate_code_in_file_returns_error(): void
    {
        $resp = $this->previewRows([
            ['NV-9002', 'A', '', '', '', '', '', '', '', '', 'Ky thuat', '', '', '', '', '', '', '', '', '', '', ''],
            ['NV-9002', 'B', '', '', '', '', '', '', '', '', 'Ky thuat', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
        $data = $resp->json();

        $this->assertSame(1, $data['summary']['error_rows']);
        $allErrors = collect($data['rows'])->pluck('errors')->flatten()->implode(' | ');
        $this->assertStringContainsString('trùng', $allErrors);
        $this->assertDatabaseMissing('employees', ['code' => 'NV-9002']);
    }

    // ── TC7 ──────────────────────────────────────────────────────────────────

    public function test_import_existing_code_without_update_flag_returns_error(): void
    {
        $this->makeEmployee(['code' => 'NV-9003', 'name' => 'Existing']);

        $resp = $this->previewRows([
            ['NV-9003', 'New Name', '', '', '', '', '', '', '', '', 'Ky thuat', '', '', '', '', '', '', '', '', '', '', ''],
        ], updateExisting: false);
        $data = $resp->json();

        $this->assertSame(1, $data['summary']['error_rows']);
    }

    public function test_import_existing_code_case_insensitive_match(): void
    {
        $this->makeEmployee(['code' => 'NV-9005', 'name' => 'Existing']);

        // Mã viết thường trong file phải vẫn được coi là trùng với NV-9005 đã có
        $resp = $this->previewRows([
            ['nv-9005', 'New Name', '', '', '', '', '', '', '', '', 'Ky thuat', '', '', '', '', '', '', '', '', '', '', ''],
        ], updateExisting: false);
        $data = $resp->json();

        $this->assertSame(1, $data['summary']['error_rows']);
    }

    // ── TC8 ──────────────────────────────────────────────────────────────────

    public function test_import_missing_required_field_returns_error(): void
    {
        $resp = $this->previewRows([
            ['NV-9004', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
        $data = $resp->json();

        $this->assertSame(1, $data['summary']['error_rows']);
        $errors = collect($data['rows'][0]['errors']);
        $this->assertTrue($errors->contains(fn ($e) => str_contains($e, 'Họ và tên')));
        $this->assertTrue($errors->contains(fn ($e) => str_contains($e, 'Phòng ban')));
    }

    // ── TC9 ──────────────────────────────────────────────────────────────────

    public function test_import_with_errors_confirms_writes_nothing(): void
    {
        $resp = $this->previewRows([
            ['NV-VALID', 'Valid Name', '', '', '', '', '', '', '', '', 'Ky thuat', '', '', '', '', '', '', '', '', '', '', ''],
            ['', 'No Code', '', '', '', '', '', '', '', '', 'Ky thuat', '', '', '', '', '', '', '', '', '', '', ''],
        ]);
        $data = $resp->json();
        $this->assertGreaterThan(0, $data['summary']['error_rows']);

        $confirmResp = $this->confirm($data['preview_id']);
        $confirmData = $confirmResp->json();

        $this->assertFalse($confirmData['success']);
        $this->assertSame(0, $confirmData['created']);
        $this->assertDatabaseMissing('employees', ['code' => 'NV-VALID']);
    }
}
