<?php

namespace Tests\Feature;

use App\Exports\Concerns\HasSignatureBlock;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * TC1: 3 chữ ký chia đều cột A-H (2-3-3), có merge cell, title/instruction đúng vị trí
 * TC2: 5 chữ ký vẫn chia đều được (không lỗi chia dư)
 * TC3: Có signingPlace + date label -> dòng ngày ký merge đúng, căn phải
 * TC4: Có tên người ký -> ghi vào đúng ô merge tương ứng
 * TC5: Không tên người ký -> ô tên để trống (không lỗi merge cells trùng)
 */
class HasSignatureBlockTest extends TestCase
{
    private function harness()
    {
        return new class {
            use HasSignatureBlock;

            public function call(...$args): int
            {
                return $this->writeSignatureBlock(...$args);
            }
        };
    }

    public function test_three_signers_split_evenly_across_a_to_h(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();

        $nextRow = $this->harness()->call($sheet, 1, [
            ['title' => 'Người lập biểu', 'instruction' => '(Ký, họ tên)'],
            ['title' => 'Kế toán trưởng', 'instruction' => '(Ký, họ tên)'],
            ['title' => 'Giám đốc', 'instruction' => '(Ký, họ tên, đóng dấu)'],
        ], null, null, 'A', 'H');

        // No date line (signingDateLabel null) -> title row starts at row 1.
        // 8 cols / 3 signers = spans [3,3,2] -> starts at A(1), D(4), G(7) — matches
        // the pre-migration hardcoded A/D/G layout this trait replaced.
        $this->assertSame('Người lập biểu', $sheet->getCell('A1')->getValue());
        $this->assertSame('Kế toán trưởng', $sheet->getCell('D1')->getValue());
        $this->assertSame('Giám đốc', $sheet->getCell('G1')->getValue());
        $this->assertSame('(Ký, họ tên)', $sheet->getCell('A2')->getValue());
        $this->assertGreaterThan(2, $nextRow);
    }

    public function test_five_signers_split_evenly_without_error(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $signers = [];
        for ($i = 1; $i <= 5; $i++) {
            $signers[] = ['title' => "Chức danh {$i}"];
        }

        $nextRow = $this->harness()->call($sheet, 1, $signers, null, null, 'A', 'J');

        $this->assertSame('Chức danh 1', $sheet->getCell('A1')->getValue());
        $this->assertSame('Chức danh 5', $sheet->getCell('I1')->getValue());
        $this->assertGreaterThan(1, $nextRow);
    }

    public function test_five_signers_with_remainder_across_uneven_columns(): void
    {
        // 9 cols / 5 signers -> base=1, extra=4 -> spans [2,2,2,2,1]
        // starts: A(1), C(3), E(5), G(7), I(9)
        $sheet = (new Spreadsheet())->getActiveSheet();
        $signers = [];
        for ($i = 1; $i <= 5; $i++) {
            $signers[] = ['title' => "Chức danh {$i}"];
        }

        $this->harness()->call($sheet, 1, $signers, null, null, 'A', 'I');

        $this->assertSame('Chức danh 1', $sheet->getCell('A1')->getValue());
        $this->assertSame('Chức danh 2', $sheet->getCell('C1')->getValue());
        $this->assertSame('Chức danh 3', $sheet->getCell('E1')->getValue());
        $this->assertSame('Chức danh 4', $sheet->getCell('G1')->getValue());
        $this->assertSame('Chức danh 5', $sheet->getCell('I1')->getValue());
    }

    public function test_more_signers_than_columns_throws(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $signers = [];
        for ($i = 1; $i <= 5; $i++) {
            $signers[] = ['title' => "Chức danh {$i}"];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->harness()->call($sheet, 1, $signers, null, null, 'A', 'C');
    }

    public function test_signing_place_and_date_label_written_and_right_aligned(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();

        $this->harness()->call($sheet, 1, [
            ['title' => 'Giám đốc'],
        ], 'Hải Phòng', 'ngày 03 tháng 07 năm 2026', 'A', 'H');

        $this->assertSame('Hải Phòng, ngày 03 tháng 07 năm 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame(
            \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT,
            $sheet->getStyle('A1')->getAlignment()->getHorizontal()
        );
        // Title row shifted to row 2 because date line occupies row 1
        $this->assertSame('Giám đốc', $sheet->getCell('A2')->getValue());
    }

    public function test_signer_name_written_into_merged_name_row(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();

        $this->harness()->call($sheet, 1, [
            ['title' => 'Giám đốc', 'name' => 'Nguyễn Văn A'],
        ], null, null, 'A', 'H');

        // titleRow=1, noteRow=2, nameRow=noteRow+3=5
        $this->assertSame('Nguyễn Văn A', $sheet->getCell('A5')->getValue());
    }

    public function test_missing_signer_name_leaves_name_row_blank_without_merge_conflict(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();

        $nextRow = $this->harness()->call($sheet, 1, [
            ['title' => 'Người lập biểu'],
            ['title' => 'Kế toán trưởng'],
        ], null, null, 'A', 'H');

        $this->assertSame('', (string) $sheet->getCell('A5')->getValue());
        $this->assertGreaterThan(5, $nextRow);
    }
}
