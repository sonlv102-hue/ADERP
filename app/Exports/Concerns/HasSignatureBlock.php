<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared signature-block writer for Excel exports.
 * See docs/REPORTING_STANDARDS.md and .claude/rules/reporting-standards.md.
 */
trait HasSignatureBlock
{
    /**
     * Writes a signing-date line + N-signer signature row starting at $row,
     * evenly dividing the column range [$firstCol..$lastCol] across signers.
     *
     * Caller contract: $row and the 4 rows below it must be free of any
     * pre-existing merged cells in [$firstCol..$lastCol] — this method does
     * not detect or avoid collisions with merges the caller already made.
     *
     * @param Worksheet $sheet
     * @param int $row first free row to write into
     * @param array $signers [['title' => string, 'instruction' => ?string, 'name' => ?string], ...]
     * @param string|null $signingPlace
     * @param string|null $signingDateLabel already-formatted label, e.g. "Ngày 03/07/2026" or "Hải Phòng, ngày 03 tháng 07 năm 2026"
     * @param string $firstCol
     * @param string $lastCol
     * @return int next free row after the signature block
     */
    protected function writeSignatureBlock(
        Worksheet $sheet,
        int $row,
        array $signers,
        ?string $signingPlace = null,
        ?string $signingDateLabel = null,
        string $firstCol = 'A',
        string $lastCol = 'H',
    ): int {
        $firstIdx = Coordinate::columnIndexFromString($firstCol);
        $lastIdx  = Coordinate::columnIndexFromString($lastCol);
        $totalCols = $lastIdx - $firstIdx + 1;
        $signerCount = max(1, count($signers));

        if ($signerCount > $totalCols) {
            throw new \InvalidArgumentException(
                "writeSignatureBlock: {$signerCount} signers cannot fit into {$totalCols} column(s) ({$firstCol}:{$lastCol}). "
                . 'Pass a wider column range.'
            );
        }

        if ($signingDateLabel !== null) {
            $label = $signingPlace ? "{$signingPlace}, {$signingDateLabel}" : $signingDateLabel;
            $sheet->mergeCells("{$firstCol}{$row}:{$lastCol}{$row}");
            $sheet->setCellValue("{$firstCol}{$row}", $label);
            $sheet->getStyle("{$firstCol}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("{$firstCol}{$row}")->getFont()->setItalic(true)->setSize(9);
            $row++;
        }

        $titleRow = $row;
        $noteRow  = $row + 1;

        // Split total columns as evenly as possible across signers.
        $base = intdiv($totalCols, $signerCount);
        $extra = $totalCols % $signerCount;
        $cursor = $firstIdx;

        foreach ($signers as $i => $signer) {
            $span = $base + ($i < $extra ? 1 : 0);
            $startCol = Coordinate::stringFromColumnIndex($cursor);
            $endCol   = Coordinate::stringFromColumnIndex($cursor + $span - 1);

            if ($span > 1) {
                $sheet->mergeCells("{$startCol}{$titleRow}:{$endCol}{$titleRow}");
                $sheet->mergeCells("{$startCol}{$noteRow}:{$endCol}{$noteRow}");
            }

            $sheet->setCellValue("{$startCol}{$titleRow}", $signer['title']);
            $sheet->getStyle("{$startCol}{$titleRow}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $sheet->setCellValue("{$startCol}{$noteRow}", $signer['instruction'] ?? '(Ký, họ tên)');
            $sheet->getStyle("{$startCol}{$noteRow}")->applyFromArray([
                'font'      => ['italic' => true, 'size' => 8],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $cursor += $span;
        }

        // Blank rows reserved for the actual signature + name.
        $nameRow = $noteRow + 3;
        $cursor = $firstIdx;
        foreach ($signers as $i => $signer) {
            $span = $base + ($i < $extra ? 1 : 0);
            $startCol = Coordinate::stringFromColumnIndex($cursor);
            $endCol   = Coordinate::stringFromColumnIndex($cursor + $span - 1);

            if (!empty($signer['name'])) {
                if ($span > 1) {
                    $sheet->mergeCells("{$startCol}{$nameRow}:{$endCol}{$nameRow}");
                }
                $sheet->setCellValue("{$startCol}{$nameRow}", $signer['name']);
                $sheet->getStyle("{$startCol}{$nameRow}")->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 9],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }

            $cursor += $span;
        }

        return $nameRow + 1;
    }
}
