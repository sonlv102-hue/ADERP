<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra bank accounts chưa cấu hình tài khoản kế toán hợp lệ.
 * Chạy trước khi bật validate tài khoản cha để biết production cần cập nhật gì.
 */
class AuditBankAccounts extends Command
{
    protected $signature = 'accounting:audit-bank-accounts
                            {--fix-suggest : Hiện câu lệnh SQL gợi ý để update}';

    protected $description = 'Kiểm tra bank accounts thiếu hoặc dùng sai tài khoản kế toán (is_detail=false)';

    public function handle(): int
    {
        $this->info('=== Kiểm tra tài khoản kế toán trên Bank Accounts ===');
        $this->newLine();

        $issues = 0;

        // 1. Bank accounts không có account_code
        $missing = BankAccount::where(function ($q) {
            $q->whereNull('account_code')->orWhere('account_code', '');
        })->orderBy('id')->get(['id', 'name', 'bank_name', 'account_number', 'account_code']);

        if ($missing->isEmpty()) {
            $this->line('  <fg=green>✓</> Không có bank account nào thiếu account_code.');
        } else {
            $issues += $missing->count();
            $this->warn("  [!] {$missing->count()} bank account chưa cấu hình account_code:");
            $this->table(
                ['ID', 'Tên', 'Ngân hàng', 'Số TK', 'Account Code'],
                $missing->map(fn ($b) => [$b->id, $b->name, $b->bank_name, $b->account_number, '(trống)'])->all()
            );
            if ($this->option('fix-suggest')) {
                $this->line('  Gợi ý (thay 1121 bằng TK phù hợp):');
                foreach ($missing as $b) {
                    $this->line("    UPDATE bank_accounts SET account_code='1121' WHERE id={$b->id}; -- {$b->name}");
                }
            }
        }

        $this->newLine();

        // 2. Bank accounts dùng TK tổng hợp (is_detail=false)
        $parentRows = DB::table('bank_accounts as ba')
            ->join('account_codes as ac', 'ac.code', '=', 'ba.account_code')
            ->where('ac.is_detail', false)
            ->whereNotNull('ba.account_code')
            ->where('ba.account_code', '!=', '')
            ->orderBy('ba.id')
            ->select('ba.id', 'ba.name', 'ba.bank_name', 'ba.account_number', 'ba.account_code', 'ac.name as ac_name')
            ->get();

        if ($parentRows->isEmpty()) {
            $this->line('  <fg=green>✓</> Không có bank account nào dùng tài khoản tổng hợp.');
        } else {
            $issues += $parentRows->count();
            $this->warn("  [!] {$parentRows->count()} bank account dùng TK tổng hợp (is_detail=false):");
            $this->table(
                ['ID', 'Tên', 'Ngân hàng', 'Số TK', 'TK kế toán', 'Tên TK'],
                $parentRows->map(fn ($r) => [$r->id, $r->name, $r->bank_name, $r->account_number, $r->account_code, $r->ac_name])->all()
            );
            $this->line('  Hành động cần thiết: đổi sang TK chi tiết (is_detail=true), ví dụ: 1121 thay vì 112.');
            if ($this->option('fix-suggest')) {
                $this->line('  Gợi ý:');
                foreach ($parentRows as $r) {
                    $this->line("    -- {$r->name} ({$r->account_number}) đang dùng {$r->account_code} ({$r->ac_name})");
                    $this->line("    UPDATE bank_accounts SET account_code='???' WHERE id={$r->id}; -- xác nhận TK chi tiết phù hợp");
                }
            }
        }

        $this->newLine();

        // 3. Bank accounts dùng account_code không tồn tại trong account_codes
        $unknownRows = DB::table('bank_accounts as ba')
            ->leftJoin('account_codes as ac', 'ac.code', '=', 'ba.account_code')
            ->whereNotNull('ba.account_code')
            ->where('ba.account_code', '!=', '')
            ->whereNull('ac.code')
            ->orderBy('ba.id')
            ->select('ba.id', 'ba.name', 'ba.bank_name', 'ba.account_number', 'ba.account_code')
            ->get();

        if ($unknownRows->isEmpty()) {
            $this->line('  <fg=green>✓</> Không có bank account nào dùng TK chưa được khai báo.');
        } else {
            $issues += $unknownRows->count();
            $this->warn("  [!] {$unknownRows->count()} bank account dùng TK chưa tồn tại trong account_codes:");
            $this->table(
                ['ID', 'Tên', 'Ngân hàng', 'Số TK', 'TK kế toán (lạ)'],
                $unknownRows->map(fn ($r) => [$r->id, $r->name, $r->bank_name, $r->account_number, $r->account_code])->all()
            );
        }

        $this->newLine();

        if ($issues === 0) {
            $this->info('Tất cả bank accounts đã cấu hình tài khoản kế toán hợp lệ.');
            return Command::SUCCESS;
        }

        $this->error("Phát hiện {$issues} vấn đề. Cần cập nhật trước khi xử lý thanh toán lương.");
        $this->line('  Chạy lại với --fix-suggest để xem gợi ý SQL.');
        return Command::FAILURE;
    }
}
