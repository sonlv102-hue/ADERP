<?php

namespace App\Services;

use App\Enums\StockExitStatus;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockExitDateService
{
    /**
     * Sửa ngày xuất kho (exit_date) của phiếu đã confirmed, đồng bộ sang
     * journal_entries.entry_date và project_wip_entries.entry_date liên quan.
     * Không sửa stock_movements/journal_entry_lines vì hai bảng này không có cột ngày riêng.
     */
    public function updateExitDate(StockExit $exit, string $newDate, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($exit, $newDate, $reason, $actor) {
            $exit = StockExit::where('id', $exit->id)->lockForUpdate()->firstOrFail();

            if ($exit->status === StockExitStatus::Cancelled) {
                throw new RuntimeException('Phiếu đã hủy, không thể sửa ngày xuất kho.');
            }

            $oldDate = $exit->exit_date->format('Y-m-d');
            $newDateCarbon = Carbon::parse($newDate);
            $newDateStr = $newDateCarbon->format('Y-m-d');

            if ($oldDate === $newDateStr) {
                throw new RuntimeException('Ngày mới trùng với ngày hiện tại, không có gì để sửa.');
            }

            $this->assertPeriodOpen(Carbon::parse($oldDate), 'ngày hiện tại');
            $this->assertPeriodOpen($newDateCarbon, 'ngày mới');

            $journals = JournalEntry::where('reference_type', 'stock_exit')
                ->where('reference_id', $exit->id)
                ->lockForUpdate()
                ->get();

            $terminal = $journals->whereIn('status', ['reversed', 'voided']);
            if ($terminal->isNotEmpty()) {
                throw new RuntimeException(
                    "Phiếu có bút toán đã {$terminal->first()->status} (mã {$terminal->first()->code}) — cần kiểm tra thủ công trước khi sửa ngày."
                );
            }

            $wips = ProjectWipEntry::where('source_type', StockExit::class)
                ->where('source_id', $exit->id)
                ->lockForUpdate()
                ->get();

            $exit->update(['exit_date' => $newDateStr]);

            $editNote = "Điều chỉnh ngày xuất kho {$exit->code} từ {$oldDate} sang {$newDateStr} bởi {$actor->name}. Lý do: {$reason}";

            foreach ($journals as $je) {
                $je->update([
                    'entry_date'     => $newDateStr,
                    'fiscal_period'  => $newDateCarbon->format('Y-m'),
                    'edited_by_user' => true,
                    'edit_reason'    => $editNote,
                ]);
            }

            foreach ($wips as $wip) {
                $wip->update(['entry_date' => $newDateStr]);
            }

            activity()
                ->performedOn($exit)
                ->causedBy($actor)
                ->withProperties(['old_date' => $oldDate, 'new_date' => $newDateStr, 'reason' => $reason])
                ->log("Sửa ngày xuất kho {$exit->code}: {$reason}");

            return [
                'old_date'      => $oldDate,
                'new_date'      => $newDateStr,
                'journal_count' => $journals->count(),
                'wip_count'     => $wips->count(),
            ];
        });
    }

    private function assertPeriodOpen(Carbon $date, string $label): void
    {
        $period = AccountingPeriod::where('year', $date->year)
            ->where('month', $date->month)
            ->first();

        if ($period && $period->status !== 'open') {
            throw new RuntimeException("Kỳ kế toán của {$label} ({$date->format('m/Y')}) đã đóng/khóa. Không thể sửa ngày xuất kho.");
        }
    }
}
