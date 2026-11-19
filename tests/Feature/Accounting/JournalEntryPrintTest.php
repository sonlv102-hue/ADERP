<?php

namespace Tests\Feature\Accounting;

use App\Exports\JournalVouchersExport;
use App\Models\AccountCode;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\JournalVoucherPrintService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Xuất Phiếu kế toán ra PDF / Excel — 1 hoặc nhiều phiếu.
 */
class JournalEntryPrintTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = app(AccountingService::class);
        $this->user = User::firstOrCreate(
            ['email' => 'je-print@test.local'],
            ['name' => 'Kế toán In', 'password' => bcrypt('pass'), 'is_active' => true]
        );
        $this->actingAs($this->user);
        Gate::before(fn ($u) => $u->email === 'je-print@test.local' ? true : null);

        foreach (['6421' => ['expense', 'debit'], '1111' => ['asset', 'debit']] as $code => [$type, $nb]) {
            AccountCode::firstOrCreate(['code' => $code], [
                'name' => 'TK ' . $code, 'type' => $type, 'normal_balance' => $nb,
                'level' => 3, 'is_detail' => true, 'is_active' => true,
            ]);
        }
    }

    private function entry(string $desc, int $amount, bool $posted = true): JournalEntry
    {
        $lines = [
            ['account' => '6421', 'debit' => $amount, 'credit' => 0, 'description' => 'Chi phí'],
            ['account' => '1111', 'debit' => 0, 'credit' => $amount],
        ];
        $date = Carbon::parse('2026-06-15');
        $e = $posted
            ? $this->accounting->post($desc, $date, $lines)
            : $this->accounting->createDraft($desc, $date, $lines);
        $e->update(['created_by' => $this->user->id]);

        return $e;
    }

    public function test_single_entry_pdf_is_rendered(): void
    {
        $e = $this->entry('Chi tiếp khách', 1_250_000);

        $res = $this->get(route('accounting.journal-entries.print-pdf', ['ids' => [$e->id]]));

        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString("PhieuKeToan-{$e->code}.pdf", $res->headers->get('content-disposition'));
    }

    public function test_multiple_entries_pdf_view_has_one_page_per_voucher(): void
    {
        $a = $this->entry('Phiếu A', 1_000_000);
        $b = $this->entry('Phiếu B', 2_000_000, posted: false);

        $svc = app(JournalVoucherPrintService::class);
        $vouchers = $svc->build([$a->id, $b->id]);
        $signers = collect($vouchers)->mapWithKeys(fn ($v) => [$v['id'] => $svc->signers($v)])->all();
        $html = view('pdf.journal-voucher', [
            'vouchers' => $vouchers, 'company' => $svc->company(), 'signingPlace' => null, 'signers' => $signers,
        ])->render();

        $this->assertSame(2, substr_count($html, 'class="title-main"'));
        $this->assertSame(1, substr_count($html, 'page-break"'));
        $this->assertStringContainsString('Nháp — chưa hạch toán', $html);   // phiếu nháp được đánh dấu
        $this->assertStringContainsString('Một triệu đồng', $html);           // số tiền bằng chữ
        $this->assertStringContainsString('Kế toán In', $html);               // tên người lập phiếu
        $this->assertStringContainsString('report-signature-section', $html); // dùng partial chữ ký chung
        $this->assertStringNotContainsString('null', strip_tags($html));
    }

    public function test_multiple_entries_excel_has_one_sheet_each(): void
    {
        Excel::fake();
        $a = $this->entry('Phiếu A', 1_000_000);
        $b = $this->entry('Phiếu B', 2_000_000);

        $this->get(route('accounting.journal-entries.print-excel', ['ids' => [$a->id, $b->id]]))->assertOk();

        Excel::assertDownloaded('PhieuKeToan_2-phieu_' . now()->format('Ymd_His') . '.xlsx',
            fn (JournalVouchersExport $x) => count($x->sheets()) === 2);
    }

    public function test_excel_content_matches_voucher(): void
    {
        $e = $this->entry('Chi tiếp khách', 1_250_000);

        $res = $this->get(route('accounting.journal-entries.print-excel', ['ids' => [$e->id]]));
        $res->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'jv') . '.xlsx';
        file_put_contents($path, $res->streamedContent() ?: file_get_contents($res->baseResponse->getFile()->getPathname()));
        $sheet = IOFactory::load($path)->getSheet(0);
        @unlink($path);

        $this->assertSame('PHIẾU KẾ TOÁN', $sheet->getCell('A5')->getValue());
        $this->assertSame('6421', $sheet->getCell('B11')->getValue());
        $this->assertEquals(1_250_000, $sheet->getCell('F11')->getValue());
        $this->assertEquals(1_250_000, $sheet->getCell('G12')->getValue());
        $this->assertSame('TỔNG CỘNG', $sheet->getCell('A13')->getValue());
        $this->assertStringContainsString('Một triệu hai trăm năm mươi nghìn đồng', $sheet->getCell('A15')->getValue());
    }

    public function test_requires_ids_and_limit(): void
    {
        $this->get(route('accounting.journal-entries.print-pdf'))->assertSessionHasErrors('ids');
        $ids = range(1, JournalVoucherPrintService::MAX_ENTRIES + 1);
        $this->get(route('accounting.journal-entries.print-pdf', ['ids' => $ids]))->assertSessionHasErrors('ids');
    }

    public function test_unknown_ids_return_404(): void
    {
        $this->get(route('accounting.journal-entries.print-pdf', ['ids' => [999999]]))->assertNotFound();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $e = $this->entry('Phiếu A', 1_000_000);
        $this->actingAs(User::create(['name' => 'X', 'email' => 'np-print@test.local', 'password' => bcrypt('p'), 'is_active' => true]));

        $this->get(route('accounting.journal-entries.print-pdf', ['ids' => [$e->id]]))->assertForbidden();
        $this->get(route('accounting.journal-entries.print-excel', ['ids' => [$e->id]]))->assertForbidden();
    }

    public function test_list_excel_export_works(): void
    {
        $this->entry('Phiếu A', 1_000_000);

        $this->get(route('accounting.journal-entries.export-excel'))->assertOk();
    }
}
