<?php

namespace App\Services;

use App\Models\AccountCode;
use App\Models\AccountingPeriod;
use App\Models\Project;
use App\Models\ProjectWipCorrectionLog;
use App\Models\ProjectWipEntry;
use App\Models\StockExit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectWipCorrectionService
{
    public function __construct(private AccountingService $accounting) {}

    // ─── Preview ─────────────────────────────────────────────────────────────

    public function previewCancel(ProjectWipEntry $entry): array
    {
        $this->assertCancellable($entry);

        $wipAccount = AccountingSettings::get('project_wip_account', '154');
        $jeLines    = [];

        if ($entry->journal_entry_id && $entry->journalEntry?->status === 'posted') {
            foreach ($entry->journalEntry->lines as $line) {
                $jeLines[] = [
                    'account_code' => $line->account_code,
                    'debit'        => $line->credit,   // đảo
                    'credit'       => $line->debit,
                    'description'  => $line->description,
                ];
            }
        }

        return [
            'action'      => 'cancel',
            'entry'       => $this->entryPreviewData($entry),
            'je_lines'    => $jeLines,
            'warning'     => $jeLines ? null : 'Dòng WIP này không có bút toán — chỉ cập nhật trạng thái, không tạo bút toán đảo.',
            'period_info' => $this->periodInfo($entry->entry_date),
        ];
    }

    public function previewTransfer(ProjectWipEntry $entry, Project $targetProject): array
    {
        $this->assertActive($entry);
        if ($targetProject->id === $entry->project_id) {
            throw new \RuntimeException('Dự án đích phải khác dự án hiện tại.');
        }

        $wipAccount = AccountingSettings::get('project_wip_account', '154');
        $amount     = (int) abs($entry->amount);

        return [
            'action'         => 'transfer',
            'entry'          => $this->entryPreviewData($entry),
            'target_project' => ['id' => $targetProject->id, 'code' => $targetProject->code, 'name' => $targetProject->name],
            'je_lines'       => [
                ['account_code' => $wipAccount, 'debit' => $amount,  'credit' => 0,       'description' => "Chuyển CP dự án {$targetProject->code}", 'project_id' => $targetProject->id],
                ['account_code' => $wipAccount, 'debit' => 0,        'credit' => $amount, 'description' => "Chuyển CP sang {$targetProject->code}",  'project_id' => $entry->project_id],
            ],
            'period_info' => $this->periodInfo(now()),
        ];
    }

    public function previewReclass(ProjectWipEntry $entry, string $targetAccountCode): array
    {
        $this->assertActive($entry);

        $targetAccount = AccountCode::find($targetAccountCode);
        if (!$targetAccount) {
            throw new \RuntimeException("Tài khoản {$targetAccountCode} không tồn tại.");
        }
        if (!$targetAccount->is_detail) {
            throw new \RuntimeException("Tài khoản {$targetAccountCode} là tài khoản tổng hợp, không được dùng trực tiếp.");
        }

        $wipAccount = AccountingSettings::get('project_wip_account', '154');
        $amount     = (int) abs($entry->amount);

        return [
            'action'         => 'reclass',
            'entry'          => $this->entryPreviewData($entry),
            'target_account' => ['code' => $targetAccount->id, 'name' => $targetAccount->name],
            'je_lines'       => [
                ['account_code' => $targetAccountCode, 'debit' => $amount,  'credit' => 0,       'description' => "Điều chỉnh TK từ {$wipAccount}"],
                ['account_code' => $wipAccount,         'debit' => 0,        'credit' => $amount, 'description' => "Chuyển sang {$targetAccountCode}", 'project_id' => $entry->project_id],
            ],
            'period_info' => $this->periodInfo(now()),
        ];
    }

    // ─── Execute ─────────────────────────────────────────────────────────────

    public function cancelEntry(ProjectWipEntry $entry, string $reason): ProjectWipCorrectionLog
    {
        $this->assertCancellable($entry);

        return DB::transaction(function () use ($entry, $reason) {
            $correctionJe = null;

            if ($entry->journal_entry_id) {
                $je = $entry->journalEntry;
                if ($je && $je->status === 'posted') {
                    $correctionJe = $this->accounting->reverse(
                        $je,
                        "Hủy chi phí dở dang dự án {$entry->project?->code}: {$reason}",
                        now()
                    );
                }
            }

            $entry->update([
                'status'       => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            $log = ProjectWipCorrectionLog::create([
                'wip_entry_id'   => $entry->id,
                'action_type'    => 'cancel',
                'from_project_id'=> $entry->project_id,
                'amount'         => (int) $entry->amount,
                'reason'         => $reason,
                'performed_by'   => auth()->id(),
                'correction_je_id' => $correctionJe?->id,
            ]);

            activity()
                ->performedOn($entry->project)
                ->causedBy(auth()->user())
                ->withProperties(['wip_entry_id' => $entry->id, 'reason' => $reason, 'je_id' => $correctionJe?->id])
                ->log('Hủy chi phí dở dang TK 154');

            return $log;
        });
    }

    public function transferToProject(ProjectWipEntry $entry, Project $targetProject, string $reason): ProjectWipCorrectionLog
    {
        $this->assertActive($entry);
        if ($targetProject->id === $entry->project_id) {
            throw new \RuntimeException('Dự án đích phải khác dự án hiện tại.');
        }

        return DB::transaction(function () use ($entry, $targetProject, $reason) {
            $wipAccount = AccountingSettings::get('project_wip_account', '154');
            $amount     = (int) abs($entry->amount);
            $fromProject = $entry->project;

            $je = $this->accounting->post(
                "Chuyển CP dở dang từ {$fromProject?->code} sang {$targetProject->code}: {$reason}",
                now(),
                [
                    ['account' => $wipAccount, 'debit' => $amount, 'credit' => 0,
                     'description' => "Nhận CP từ {$fromProject?->code}", 'project_id' => $targetProject->id],
                    ['account' => $wipAccount, 'debit' => 0, 'credit' => $amount,
                     'description' => "Chuyển CP sang {$targetProject->code}", 'project_id' => $entry->project_id],
                ],
                ProjectWipEntry::class, $entry->id, false
            );

            // Tạo WIP entry trên dự án đích
            $newEntry = ProjectWipEntry::create([
                'project_id'      => $targetProject->id,
                'source_type'     => $entry->source_type,
                'source_id'       => $entry->source_id,
                'cost_type'       => $entry->cost_type,
                'amount'          => $amount,
                'description'     => "Nhận từ {$fromProject?->code}: {$entry->description}",
                'entry_date'      => now()->toDateString(),
                'journal_entry_id'=> $je->id,
                'created_by'      => auth()->id(),
                'product_id'      => $entry->product_id,
                'quantity'        => $entry->quantity,
                'unit_cost'       => $entry->unit_cost,
                'status'          => 'active',
                'correction_of_id'=> $entry->id,
            ]);

            $entry->update([
                'status'       => 'transferred',
                'cancel_reason' => "Chuyển sang {$targetProject->code}: {$reason}",
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            $log = ProjectWipCorrectionLog::create([
                'wip_entry_id'    => $entry->id,
                'action_type'     => 'transfer',
                'from_project_id' => $entry->project_id,
                'to_project_id'   => $targetProject->id,
                'amount'          => $amount,
                'reason'          => $reason,
                'performed_by'    => auth()->id(),
                'correction_je_id'=> $je->id,
                'new_wip_entry_id'=> $newEntry->id,
            ]);

            activity()
                ->performedOn($fromProject)
                ->causedBy(auth()->user())
                ->withProperties([
                    'wip_entry_id'    => $entry->id,
                    'to_project'      => $targetProject->code,
                    'new_wip_entry_id'=> $newEntry->id,
                    'reason'          => $reason,
                ])
                ->log("Chuyển chi phí TK 154 sang dự án {$targetProject->code}");

            return $log;
        });
    }

    public function reclassAccount(ProjectWipEntry $entry, string $targetAccountCode, string $reason): ProjectWipCorrectionLog
    {
        $this->assertActive($entry);

        $targetAccount = AccountCode::find($targetAccountCode);
        if (!$targetAccount) {
            throw new \RuntimeException("Tài khoản {$targetAccountCode} không tồn tại.");
        }
        if (!$targetAccount->is_detail) {
            throw new \RuntimeException("Tài khoản {$targetAccountCode} là tài khoản tổng hợp.");
        }

        return DB::transaction(function () use ($entry, $targetAccountCode, $targetAccount, $reason) {
            $wipAccount = AccountingSettings::get('project_wip_account', '154');
            $amount     = (int) abs($entry->amount);

            $je = $this->accounting->post(
                "Điều chỉnh TK chi phí dự án {$entry->project?->code}: {$reason}",
                now(),
                [
                    ['account' => $targetAccountCode, 'debit' => $amount, 'credit' => 0,
                     'description' => "Điều chỉnh từ {$wipAccount}", 'project_id' => $entry->project_id],
                    ['account' => $wipAccount,         'debit' => 0, 'credit' => $amount,
                     'description' => "Chuyển sang {$targetAccountCode} - {$targetAccount->name}", 'project_id' => $entry->project_id],
                ],
                ProjectWipEntry::class, $entry->id, false
            );

            $entry->update([
                'status'       => 'adjusted',
                'cancel_reason' => "Chuyển sang {$targetAccountCode}: {$reason}",
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            $log = ProjectWipCorrectionLog::create([
                'wip_entry_id'    => $entry->id,
                'action_type'     => 'reclass',
                'from_project_id' => $entry->project_id,
                'from_account'    => $wipAccount,
                'to_account'      => $targetAccountCode,
                'amount'          => $amount,
                'reason'          => $reason,
                'performed_by'    => auth()->id(),
                'correction_je_id'=> $je->id,
            ]);

            activity()
                ->performedOn($entry->project)
                ->causedBy(auth()->user())
                ->withProperties([
                    'wip_entry_id' => $entry->id,
                    'from_account' => $wipAccount,
                    'to_account'   => $targetAccountCode,
                    'reason'       => $reason,
                ])
                ->log("Điều chỉnh TK chi phí dự án {$entry->project?->code}");

            return $log;
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function assertActive(ProjectWipEntry $entry): void
    {
        if (($entry->status ?? 'active') !== 'active') {
            throw new \RuntimeException("Dòng chi phí này đã {$entry->statusLabel()} — không thể thực hiện thêm.");
        }
    }

    private function assertCancellable(ProjectWipEntry $entry): void
    {
        $this->assertActive($entry);

        // StockExit source không cho direct cancel — phải hủy phiếu xuất
        if ($entry->source_type === StockExit::class || $entry->source_type === 'App\\Models\\StockExit') {
            throw new \RuntimeException('Dòng chi phí từ phiếu xuất kho phải được hủy thông qua màn hình Phiếu xuất kho. Không hủy trực tiếp ở đây.');
        }
    }

    private function entryPreviewData(ProjectWipEntry $entry): array
    {
        return [
            'id'          => $entry->id,
            'description' => $entry->description,
            'amount'      => (int) $entry->amount,
            'entry_date'  => $entry->entry_date?->format('d/m/Y'),
            'cost_type'   => $entry->cost_type,
            'source_type' => class_basename($entry->source_type ?? ''),
            'journal_code'=> $entry->journalEntry?->code,
            'status'      => $entry->status ?? 'active',
        ];
    }

    private function periodInfo(Carbon|\DateTimeInterface|string $date): array
    {
        $carbon = Carbon::parse($date);
        $period = $carbon->format('Y-m');
        $locked = AccountingPeriod::where('year', $carbon->year)
            ->where('month', $carbon->month)
            ->whereIn('status', ['closed', 'locked'])
            ->exists();

        return ['period' => $period, 'is_locked' => $locked];
    }
}
