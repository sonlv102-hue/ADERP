<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class JournalEntryAuditProjectDimensionsCommand extends Command
{
    protected $signature = 'journal-entries:audit-project-dimensions';

    protected $description = 'Kiểm tra dòng bút toán Nợ TK154 thiếu project_id/cost_group/WIP (giống lớp lỗi G2).';

    public function handle(): int
    {
        $this->info('=== Rà soát chiều dự án trên bút toán Nợ TK154 ===');
        $this->newLine();

        // Chỉ soi Phiếu kế toán thủ công (reference_type null) — bút toán tự động từ StockService/
        // ProjectWipService/... đã có project_id đúng qua luồng riêng và không dùng cost_group,
        // nên không thuộc phạm vi lớp lỗi G2 (thiếu chỗ chọn dự án/nhóm chi phí trên JE thủ công).
        $rows = DB::table('journal_entry_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_code', 'like', '154%')
            ->where('jl.debit', '>', 0)
            ->whereIn('je.status', ['posted'])
            ->whereNull('je.reference_type')
            ->select(
                'jl.id as line_id', 'je.id as je_id', 'je.code as je_code', 'je.entry_date',
                'jl.account_code', 'jl.debit', 'jl.project_id', 'jl.cost_group'
            )
            ->orderBy('je.entry_date')
            ->get();

        // WIP theo journal_entry_line_id — 1 query duy nhất, tránh N+1
        $wipByLine = DB::table('project_wip_entries')
            ->whereNotNull('journal_entry_line_id')
            ->select('journal_entry_line_id', DB::raw('count(*) as cnt'), DB::raw('sum(amount) as total_amount'))
            ->groupBy('journal_entry_line_id')
            ->get()
            ->keyBy('journal_entry_line_id');

        $findings = [];

        foreach ($rows as $row) {
            $wip = $wipByLine->get($row->line_id);
            $hasWip = $wip && (int) $wip->cnt > 0;

            if (empty($row->project_id)) {
                $findings[] = $this->finding($row, $hasWip, 'MISSING_PROJECT_ID', 'Chọn Dự án cho dòng bút toán rồi ghi bổ sung project_id.');
            }
            if (empty($row->cost_group)) {
                $findings[] = $this->finding($row, $hasWip, 'MISSING_COST_GROUP', 'Chọn Nhóm chi phí cho dòng bút toán.');
            }
            if (! empty($row->project_id) && ! empty($row->cost_group) && ! $hasWip) {
                $findings[] = $this->finding($row, $hasWip, 'MISSING_WIP', 'Đủ project_id+cost_group nhưng chưa có project_wip_entries — kiểm tra vì sao WIP không được tạo khi post.');
            }
            if ($wip && (int) $wip->cnt > 1) {
                $findings[] = $this->finding($row, $hasWip, 'DUPLICATE_WIP', "Có {$wip->cnt} project_wip_entries cùng journal_entry_line_id — kiểm tra trùng lặp.");
            }
            if ($wip && (int) $wip->cnt === 1 && abs((float) $wip->total_amount - (float) $row->debit) > 0.5) {
                $findings[] = $this->finding($row, $hasWip, 'WIP_AMOUNT_MISMATCH', "WIP amount ({$wip->total_amount}) khác debit dòng ({$row->debit}) — kiểm tra chỉnh sửa lệch nhau.");
            }
        }

        if (empty($findings)) {
            $this->info('Không phát hiện vấn đề nào.');
            return 0;
        }

        $this->table(
            ['JE ID', 'Mã BT', 'Ngày', 'TK', 'Nợ', 'project_id', 'cost_group', 'has_wip', 'issue_code', 'Đề xuất'],
            array_map(fn ($f) => [
                $f['journal_entry_id'], $f['journal_code'], $f['entry_date'], $f['account_code'],
                number_format($f['debit']), $f['project_id'] ?? '—', $f['cost_group'] ?? '—',
                $f['has_wip'] ? 'yes' : 'no', $f['issue_code'], $f['suggested_action'],
            ], $findings)
        );

        $this->newLine();
        $this->warn(count($findings) . ' vấn đề được phát hiện. Chỉ audit — KHÔNG tự sửa dữ liệu.');

        return 1;
    }

    private function finding(object $row, bool $hasWip, string $issueCode, string $suggestedAction): array
    {
        return [
            'journal_entry_id' => $row->je_id,
            'journal_code'     => $row->je_code,
            'entry_date'       => $row->entry_date,
            'account_code'     => $row->account_code,
            'debit'            => (float) $row->debit,
            'project_id'       => $row->project_id,
            'cost_group'       => $row->cost_group,
            'has_wip'          => $hasWip,
            'issue_code'       => $issueCode,
            'suggested_action' => $suggestedAction,
        ];
    }
}
