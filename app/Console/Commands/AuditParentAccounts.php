<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit toàn bộ journal_entry_lines đang dùng tài khoản cha (is_detail=false).
 * Báo cáo chi tiết: JE, ngày, chứng từ nguồn, tài khoản đang dùng,
 * đề xuất tài khoản con, Nợ/Có, số tiền, đối tác liên quan.
 * Mức độ lỗi: POSTED (nghiêm trọng) | DRAFT (có thể sửa trực tiếp).
 *
 * Command CHỈ audit, không tự sửa dữ liệu.
 */
class AuditParentAccounts extends Command
{
    protected $signature = 'accounting:audit-parent-accounts
                                {--account= : Lọc theo mã tài khoản cụ thể (vd: 131)}
                                {--export   : Xuất kết quả ra file CSV}';

    protected $description = 'Audit toàn bộ journal lines dùng tài khoản cha (is_detail=false)';

    public function handle(): int
    {
        $filterAccount = $this->option('account');

        // Lấy tất cả tài khoản cha đang được dùng trong journal_entry_lines
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je',    'je.id',   '=', 'jel.journal_entry_id')
            ->join('account_codes as ac',      'ac.code', '=', 'jel.account_code')
            ->leftJoin('customers as cust', function ($join) {
                $join->on('cust.id', '=', DB::raw('(jel.partner_id)::int'))
                     ->whereRaw("jel.partner_type = 'customer'");
            })
            ->leftJoin('suppliers as supp', function ($join) {
                $join->on('supp.id', '=', DB::raw('(jel.partner_id)::int'))
                     ->whereRaw("jel.partner_type = 'supplier'");
            })
            ->where('ac.is_detail', false)
            // Bỏ qua các bút toán điều chỉnh tạo bởi apply-payable-adjustment
            ->where(fn ($q) => $q->whereNull('je.source_type')
                                  ->orWhere('je.source_type', '!=', 'payable_reclassification'))
            ->select(
                'je.id as je_id',
                'je.code as je_code',
                'je.status',
                'je.entry_date',
                'je.reference_type',
                'je.reference_id',
                'je.source_type',
                'jel.id as line_id',
                'jel.account_code',
                'ac.name as account_name',
                'jel.debit',
                'jel.credit',
                'jel.description as line_desc',
                'jel.partner_type',
                'jel.partner_id',
                DB::raw("COALESCE(cust.name, supp.name) as party_name"),
                DB::raw("COALESCE(cust.receivable_account_code, supp.payable_account_code) as suggested_account"),
            )
            ->orderBy('jel.account_code')
            ->orderBy('je.id');

        if ($filterAccount) {
            $query->where('jel.account_code', $filterAccount);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('Không có journal line nào dùng tài khoản cha. Hệ thống sạch.');
            return self::SUCCESS;
        }

        $posted = $rows->where('status', 'posted');
        $draft  = $rows->where('status', 'draft');

        $this->line('');
        $this->error("Tổng: {$rows->count()} dòng dùng tài khoản cha.");
        $this->line("  POSTED (nghiêm trọng — cần bút toán điều chỉnh): {$posted->count()} dòng");
        $this->line("  DRAFT  (có thể sửa trực tiếp):                   {$draft->count()} dòng");
        $this->line('');

        // Nhóm theo account_code
        $byAccount = $rows->groupBy('account_code');

        foreach ($byAccount as $accountCode => $accountRows) {
            $acName   = $accountRows->first()->account_name;
            $postedN  = $accountRows->where('status', 'posted')->count();
            $draftN   = $accountRows->where('status', 'draft')->count();

            $this->info("── TK {$accountCode} ({$acName}) — {$accountRows->count()} dòng [{$postedN} posted / {$draftN} draft] ──");

            $this->table(
                ['JE', 'Ngày', 'Status', 'Ref type', 'Nợ', 'Có', 'Đối tác', 'Đề xuất TK', 'Mô tả'],
                $accountRows->map(fn ($r) => [
                    $r->je_code,
                    substr($r->entry_date, 0, 10),
                    $r->status === 'posted' ? '⚠ posted' : $r->status,
                    $r->reference_type ?? '',
                    $r->debit  > 0 ? number_format($r->debit)  : '',
                    $r->credit > 0 ? number_format($r->credit) : '',
                    mb_strimwidth($r->party_name ?? '', 0, 25, '…'),
                    $r->suggested_account ?? $this->suggestAccount($accountCode, $r),
                    mb_strimwidth($r->line_desc ?? '', 0, 35, '…'),
                ])->all()
            );

            $this->line('');
        }

        // Hướng dẫn xử lý
        $this->warn('Phương án xử lý:');
        $this->line('  DRAFT  → Sửa trực tiếp account_code trên dòng JE (hoặc xóa + tạo lại).');
        $this->line('  POSTED → Tạo bút toán điều chỉnh: php artisan accounting:apply-payable-adjustment');
        $this->line('  131    → Cần customer->receivable_account_code (mặc định 1311).');
        $this->line('  511    → Dùng 5111 (hàng hóa) hoặc 5113 (dịch vụ).');
        $this->line('  Các TK khác → Tham khảo kế toán để xác định TK chi tiết phù hợp.');

        if ($this->option('export')) {
            $this->exportCsv($rows);
        }

        return self::SUCCESS;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function suggestAccount(string $code, object $row): string
    {
        return match(true) {
            $code === '131'  => $row->suggested_account ?? '1311',
            $code === '331'  => $row->suggested_account ?? '3311',
            $code === '511'  => '5111 / 5113',
            $code === '112'  => '1121',
            $code === '111'  => '1111',
            str_starts_with($code, '333') => '3331x',
            str_starts_with($code, '338') => '3382/3383/3384/3385',
            str_starts_with($code, '411') => '4111',
            str_starts_with($code, '421') => '4211',
            default => '(xem chart of accounts)',
        };
    }

    private function exportCsv($rows): void
    {
        $filename = storage_path('logs/audit_parent_accounts_' . now()->format('Ymd_His') . '.csv');
        $fp = fopen($filename, 'w');
        fputcsv($fp, [
            'je_code','entry_date','status','reference_type','account_code','account_name',
            'debit','credit','party_name','suggested_account','line_desc','source_type',
        ]);
        foreach ($rows as $r) {
            fputcsv($fp, [
                $r->je_code, substr($r->entry_date, 0, 10), $r->status,
                $r->reference_type, $r->account_code, $r->account_name,
                $r->debit, $r->credit, $r->party_name,
                $r->suggested_account ?? $this->suggestAccount($r->account_code, $r),
                $r->line_desc, $r->source_type,
            ]);
        }
        fclose($fp);
        $this->info("Đã xuất: {$filename}");
    }
}
