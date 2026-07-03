<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * TC1: 3 chữ ký ngang, chia đều cột, có title/instruction
 * TC2: 1/2/4/5 chữ ký vẫn render đúng số cột, không lỗi
 * TC3: Thiếu tên người ký -> không render "null"/"undefined", ô để trống
 * TC4: Thiếu signingPlace -> chỉ hiện "ngày ...", không có dấu phẩy thừa
 * TC5: showSigningDate=false -> không render dòng ngày ký
 * TC6: page-break-inside: avoid có mặt trong style
 * TC7: Chức danh/tên dài, có dấu tiếng Việt -> render nguyên vẹn, escaped đúng
 */
class ReportSignatureSectionTest extends TestCase
{
    private function render(array $data): string
    {
        return view('pdf.partials.signature-section', $data)->render();
    }

    public function test_three_signers_render_horizontally_with_equal_columns(): void
    {
        $html = $this->render([
            'signingPlace' => 'Hải Phòng',
            'signingDate'  => Carbon::parse('2026-07-03'),
            'signers' => [
                ['title' => 'NGƯỜI LẬP BIỂU', 'instruction' => '(Ký, ghi rõ họ tên)'],
                ['title' => 'KẾ TOÁN TRƯỞNG', 'instruction' => '(Ký, ghi rõ họ tên)'],
                ['title' => 'GIÁM ĐỐC', 'instruction' => '(Ký, ghi rõ họ tên, đóng dấu)'],
            ],
        ]);

        $this->assertSame(3, substr_count($html, 'class="report-signature-title"'));
        $this->assertStringContainsString('width: 33.3333%', $html);
        $this->assertStringContainsString('Hải Phòng, ngày 03', $html);
        $this->assertStringContainsString('tháng 07', $html);
        $this->assertStringContainsString('năm 2026', $html);
        $this->assertStringContainsString('NGƯỜI LẬP BIỂU', $html);
        $this->assertStringContainsString('KẾ TOÁN TRƯỞNG', $html);
        $this->assertStringContainsString('GIÁM ĐỐC', $html);
    }

    public function test_signer_counts_from_one_to_five(): void
    {
        foreach ([1, 2, 4, 5] as $count) {
            $signers = [];
            for ($i = 1; $i <= $count; $i++) {
                $signers[] = ['title' => "CHỨC DANH {$i}"];
            }

            $html = $this->render(['signers' => $signers, 'showSigningDate' => false]);

            $this->assertSame($count, substr_count($html, 'class="report-signature-title"'), "Failed for {$count} signers");
            $expectedWidth = number_format(100 / $count, 4, '.', '');
            $this->assertStringContainsString("width: {$expectedWidth}%", $html);
        }
    }

    public function test_missing_signer_name_renders_blank_not_null_or_undefined(): void
    {
        $html = $this->render([
            'signers' => [['title' => 'GIÁM ĐỐC']],
            'showSigningDate' => false,
        ]);

        $this->assertStringNotContainsString('null', strtolower($html));
        $this->assertStringNotContainsString('undefined', strtolower($html));
    }

    public function test_missing_signing_place_has_no_stray_comma(): void
    {
        $html = $this->render([
            'signingPlace' => null,
            'signingDate'  => Carbon::parse('2026-07-03'),
            'signers'      => [['title' => 'GIÁM ĐỐC']],
        ]);

        $this->assertStringContainsString('ngày 03', $html);
        $this->assertStringNotContainsString(', ngày', $html);
    }

    public function test_show_signing_date_false_hides_date_line(): void
    {
        $html = $this->render([
            'signingPlace'    => 'Hải Phòng',
            'signingDate'     => Carbon::now(),
            'signers'         => [['title' => 'GIÁM ĐỐC']],
            'showSigningDate' => false,
        ]);

        $this->assertStringNotContainsString('class="report-signing-date"', $html);
    }

    public function test_page_break_avoid_present(): void
    {
        $html = $this->render(['signers' => [['title' => 'GIÁM ĐỐC']], 'showSigningDate' => false]);

        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('break-inside: avoid', $html);
    }

    public function test_long_titles_and_names_with_vietnamese_diacritics_render_intact(): void
    {
        $longTitle = 'TRƯỞNG PHÒNG KẾ TOÁN TÀI CHÍNH KIÊM PHỤ TRÁCH KIỂM SOÁT NỘI BỘ';
        $longName  = 'Nguyễn Thị Hoài-Lacy Phương Uyển';

        $html = $this->render([
            'signers' => [['title' => $longTitle, 'name' => $longName]],
            'showSigningDate' => false,
        ]);

        $this->assertStringContainsString($longTitle, $html);
        $this->assertStringContainsString($longName, $html);
    }
}
