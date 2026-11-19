<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Xuất 1 hoặc nhiều Phiếu kế toán ra Excel — mỗi phiếu một sheet (in được từng trang A4).
 */
class JournalVouchersExport implements WithMultipleSheets
{
    public function __construct(
        private array $vouchers,
        private array $company,
        private ?string $signingPlace,
        private array $signers,
    ) {}

    public function sheets(): array
    {
        $used = [];

        return array_map(function (array $v) use (&$used) {
            $title = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/u', '-', $v['code']) ?: 'Phieu', 0, 28);
            $base  = $title;
            for ($n = 2; isset($used[mb_strtolower($title)]); $n++) {
                $title = "{$base}_{$n}";
            }
            $used[mb_strtolower($title)] = true;

            return new JournalVoucherSheet($v, $this->company, $this->signingPlace, $this->signers[$v['id']], $title);
        }, $this->vouchers);
    }
}
