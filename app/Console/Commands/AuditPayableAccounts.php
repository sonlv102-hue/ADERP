<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Liệt kê tất cả dòng JE đang dùng TK '331' (tài khoản cha/tổng hợp).
 * Cố gắng khớp supplier qua partner_id/partner_type để đề xuất TK đúng.
 * Các dòng không xác định được supplier → đánh dấu cần kế toán xử lý thủ công.
 *
 * Lưu ý: Command này CHỈ audit, KHÔNG tự sửa dữ liệu.
 * Để sửa, kế toán cần dùng bút toán điều chỉnh hoặc xác nhận với admin.
 */
class AuditPayableAccounts extends Command
{
    protected $signature   = 'accounting:audit-payable-accounts {--export : Xuất ra file CSV}';
    protected $description = 'Liệt kê các dòng JE đang dùng TK 331 (cha), đề xuất TK chi tiết theo supplier';

    public function handle(): int
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->leftJoin('suppliers as s', function ($join) {
                $join->on('s.id', '=', DB::raw("(jel.partner_id)::int"))
                     ->whereRaw("jel.partner_type = 'supplier'");
            })
            ->where('jel.account_code', '331')
            ->where(fn ($q) => $q->whereNull('je.source_type')
                                  ->orWhere('je.source_type', '!=', 'payable_reclassification'))
            ->select(
                'je.code as je_code',
                'je.status',
                'je.reference_type',
                'jel.debit',
                'jel.credit',
                'jel.description as line_desc',
                'jel.partner_id',
                's.name as supplier_name',
                's.payable_account_code as suggested_account'
            )
            ->orderBy('je.id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Không còn dòng JE nào dùng TK 331. Hệ thống sạch.');
            return self::SUCCESS;
        }

        $matched   = $rows->whereNotNull('supplier_name');
        $unmatched = $rows->whereNull('supplier_name');

        $this->info("Tổng: {$rows->count()} dòng dùng TK 331.");
        $this->line('');

        if ($matched->isNotEmpty()) {
            $this->info('=== Xác định được Supplier — đề xuất TK chi tiết ===');
            $this->table(
                ['Bút toán', 'Status', 'Nợ', 'Có', 'Supplier', 'TK đề xuất', 'Mô tả'],
                $matched->map(fn ($r) => [
                    $r->je_code,
                    $r->status,
                    $r->debit   > 0 ? number_format($r->debit)   : '',
                    $r->credit  > 0 ? number_format($r->credit)  : '',
                    $r->supplier_name,
                    $r->suggested_account ?? '(NCC chưa cấu hình)',
                    mb_strimwidth($r->line_desc ?? '', 0, 50, '…'),
                ])->all()
            );
        }

        if ($unmatched->isNotEmpty()) {
            $this->line('');
            $this->warn('=== Không xác định được Supplier — cần kế toán xử lý thủ công ===');
            $this->table(
                ['Bút toán', 'Status', 'Ref type', 'Nợ', 'Có', 'Mô tả'],
                $unmatched->map(fn ($r) => [
                    $r->je_code,
                    $r->status,
                    $r->reference_type ?? '',
                    $r->debit  > 0 ? number_format($r->debit)  : '',
                    $r->credit > 0 ? number_format($r->credit) : '',
                    mb_strimwidth($r->line_desc ?? '', 0, 60, '…'),
                ])->all()
            );
        }

        if ($this->option('export')) {
            $filename = storage_path('logs/audit_payable_' . now()->format('Ymd_His') . '.csv');
            $fp = fopen($filename, 'w');
            fputcsv($fp, ['je_code','status','reference_type','debit','credit','supplier_name','suggested_account','line_desc']);
            foreach ($rows as $r) {
                fputcsv($fp, [$r->je_code,$r->status,$r->reference_type,$r->debit,$r->credit,$r->supplier_name,$r->suggested_account,$r->line_desc]);
            }
            fclose($fp);
            $this->info("Đã xuất: {$filename}");
        }

        $this->line('');
        $this->warn('Lưu ý: Command này chỉ audit, không tự sửa dữ liệu JE đã ghi sổ.');

        return self::SUCCESS;
    }
}
