<?php

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\JournalEntry;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn bị dữ liệu in/xuất Phiếu kế toán (PDF + Excel dùng chung một nguồn số liệu).
 * Bố cục ký theo docs/REPORTING_STANDARDS.md.
 */
class JournalVoucherPrintService
{
    public const MAX_ENTRIES = 200;

    /** @return array<int, array> danh sách phiếu, sắp theo ngày HT rồi mã */
    public function build(array $ids): array
    {
        $entries = JournalEntry::with(['lines.account', 'lines.project', 'creator'])
            ->whereIn('id', $ids)
            ->orderBy('entry_date')
            ->orderBy('code')
            ->get();

        $partnerNames = $this->resolvePartnerNames($entries->flatMap->lines);

        return $entries->map(fn (JournalEntry $e) => $this->mapEntry($e, $partnerNames))->all();
    }

    public function company(): array
    {
        $co = Setting::getGroup('company');

        return [
            'name'     => $co['company_name'] ?? '',
            'address'  => $co['company_address'] ?? '',
            'tax_code' => $co['company_tax_code'] ?? '',
        ];
    }

    public function signingPlace(): ?string
    {
        return Setting::get('report_signing_place') ?: null;
    }

    /** Ma trận ký Phiếu kế toán: Người lập phiếu · Kế toán trưởng · Giám đốc. */
    public function signers(array $voucher): array
    {
        return [
            ['title' => 'Người lập phiếu', 'instruction' => '(Ký, họ tên)', 'name' => $voucher['creator']],
            ['title' => 'Kế toán trưởng',  'instruction' => '(Ký, họ tên)'],
            ['title' => 'Giám đốc',        'instruction' => '(Ký, họ tên, đóng dấu)'],
        ];
    }

    private function mapEntry(JournalEntry $e, array $partnerNames): array
    {
        $lines = $e->lines->map(function ($l) use ($partnerNames) {
            $object = $l->project?->code ?? '';
            if ($l->partner_type && $l->partner_id) {
                $object = $partnerNames[$l->partner_type][(int) $l->partner_id] ?? $object;
            }

            return [
                'account_code' => $l->account_code,
                'account_name' => $l->account?->name ?? '',
                'description'  => $l->description ?? '',
                'object'       => $object,
                'debit'        => (float) $l->debit,
                'credit'       => (float) $l->credit,
            ];
        })->values()->all();

        $totalDebit = array_sum(array_column($lines, 'debit'));

        return [
            'id'           => $e->id,
            'code'         => $e->code,
            'entry_date'   => $e->entry_date,
            'description'  => $e->description ?? '',
            'notes'        => $e->notes ?? '',
            'status'       => $e->status,
            'status_label' => $e->statusLabel(),
            'is_auto'      => (bool) $e->is_auto,
            'creator'      => $e->creator?->name ?? '',
            'lines'        => $lines,
            'total_debit'  => $totalDebit,
            'total_credit' => array_sum(array_column($lines, 'credit')),
            'amount_words' => NumberToWords::toVietnamese((int) round($totalDebit)),
        ];
    }

    private function resolvePartnerNames(Collection $lines): array
    {
        $names = [];
        foreach (['customer' => 'customers', 'supplier' => 'suppliers', 'employee' => 'employees'] as $type => $table) {
            $ids = $lines->where('partner_type', $type)->pluck('partner_id')->filter()->unique();
            $names[$type] = $ids->isNotEmpty()
                ? DB::table($table)->whereIn('id', $ids)->pluck('name', 'id')->toArray()
                : [];
        }

        return $names;
    }
}
