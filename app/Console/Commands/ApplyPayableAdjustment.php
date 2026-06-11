<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tạo bút toán điều chỉnh chuyển phát sinh TK 331 (cha) sang TK chi tiết
 * (3311/3312/3318) theo payable_account_code đã cấu hình trên supplier.
 *
 * Nguyên tắc:
 *   - Không sửa JE đã posted. Chỉ tạo bút toán đối ứng mới.
 *   - Nếu original line là Dr 331 → Adj: Dr <target> / Cr 331
 *   - Nếu original line là Cr 331 → Adj: Dr 331 / Cr <target>
 *   - partner_type/partner_id ghi trên dòng <target> để AR/AP sub-ledger đúng.
 *
 * Lưu ý: Command tạo JE trực tiếp qua model (không qua AccountingService::post)
 * vì AccountingService::validateLines() từ chối TK cha. Đây là ngoại lệ
 * duy nhất được phép cho bút toán điều chỉnh lịch sử 1 lần.
 */
class ApplyPayableAdjustment extends Command
{
    protected $signature = 'accounting:apply-payable-adjustment
                                {--dry-run : Xem trước, không ghi DB}
                                {--grouped : Gộp các dòng cùng supplier/period thành 1 JE}';

    protected $description = 'Tạo bút toán điều chỉnh chuyển TK 331 (cha) sang TK chi tiết 3311/3312/3318';

    public function handle(): int
    {
        $isDry   = $this->option('dry-run');
        $grouped = $this->option('grouped');
        $today   = Carbon::today();
        $period  = $today->format('Y-m');

        // --- Lấy tất cả dòng TK 331 từ JE không phải adjustment ---
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->leftJoin('suppliers as s', function ($join) {
                $join->on('s.id', '=', DB::raw('(jel.partner_id)::int'))
                     ->whereRaw("jel.partner_type = 'supplier'");
            })
            ->where('jel.account_code', '331')
            ->where(fn ($q) => $q->whereNull('je.source_type')
                                  ->orWhere('je.source_type', '!=', 'payable_reclassification'))
            ->select(
                'jel.id as line_id',
                'jel.journal_entry_id',
                'jel.debit',
                'jel.credit',
                'jel.description as line_desc',
                'jel.partner_id',
                'jel.partner_type',
                'je.code as je_code',
                'je.entry_date',
                'je.fiscal_period',
                's.id as supplier_id',
                's.name as supplier_name',
                's.payable_account_code',
            )
            ->orderBy('je.id')
            ->get();

        // --- Loại trừ line đã được điều chỉnh (idempotency) ---
        $alreadyAdjusted = DB::table('journal_entries')
            ->where('source_type', 'payable_reclassification')
            ->pluck('notes')
            ->flatMap(function ($notes) {
                preg_match_all('/line_id:(\d+)/', (string) $notes, $m);
                return $m[1] ?? [];
            })
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = $rows->filter(fn ($r) => !in_array((int) $r->line_id, $alreadyAdjusted));

        if ($rows->isEmpty()) {
            $this->info('Không còn dòng nào cần điều chỉnh. Tất cả đã được xử lý.');
            return self::SUCCESS;
        }

        $this->info("Cần điều chỉnh: {$rows->count()} dòng." . ($isDry ? ' [DRY RUN — không ghi DB]' : ''));
        $this->line('');

        if ($grouped) {
            $this->applyGrouped($rows, $today, $period, $isDry);
        } else {
            $this->applyPerJe($rows, $today, $period, $isDry);
        }

        $this->line('');

        if ($isDry) {
            $this->warn('[DRY RUN] Không có thay đổi nào được ghi. Chạy lại không có --dry-run để áp dụng.');
        } else {
            $this->info('Hoàn tất. Kiểm tra lại: php artisan accounting:audit-payable-accounts');
        }

        return self::SUCCESS;
    }

    // ─── Per-JE mode (default) ────────────────────────────────────────────────

    private function applyPerJe($rows, Carbon $today, string $period, bool $isDry): void
    {
        $headers = ['JE gốc', 'Dr', 'Cr', 'Số tiền', 'Supplier'];
        $preview = [];

        foreach ($rows as $row) {
            $target  = $row->payable_account_code ?? '3311';
            $isDebit = (float) $row->debit > 0;
            $amount  = (int) ($isDebit ? $row->debit : $row->credit);

            [$drAccount, $crAccount] = $isDebit
                ? [$target, '331']
                : ['331', $target];

            $preview[] = [
                $row->je_code,
                $drAccount,
                $crAccount,
                number_format($amount),
                $row->supplier_name ?? '(không xác định)',
            ];

            if (!$isDry) {
                $this->createAdjustmentJe(
                    $today, $period, $target,
                    $row->je_code, $row->line_id, $row->journal_entry_id,
                    $drAccount, $crAccount, $amount,
                    $row->supplier_name, $row->partner_type, $row->partner_id,
                );
                $this->line("  ✓ {$row->je_code} → Dr {$drAccount} / Cr {$crAccount} " . number_format($amount));
            }
        }

        if ($isDry) {
            $this->table($headers, $preview);
        }
    }

    // ─── Grouped mode ─────────────────────────────────────────────────────────

    private function applyGrouped($rows, Carbon $today, string $period, bool $isDry): void
    {
        $groups = $rows->groupBy(fn ($r) =>
            ($r->supplier_id ?? 'unknown')
            . '|' . ($r->fiscal_period ?? $period)
            . '|' . ((float) $r->debit > 0 ? 'dr' : 'cr')
        );

        $headers = ['Supplier', 'Dr', 'Cr', 'Tổng', 'Số dòng'];
        $preview = [];

        foreach ($groups as $groupRows) {
            $first   = $groupRows->first();
            $target  = $first->payable_account_code ?? '3311';
            $isDebit = (float) $first->debit > 0;
            $total   = (int) $groupRows->sum(fn ($r) => $isDebit ? $r->debit : $r->credit);

            [$drAccount, $crAccount] = $isDebit
                ? [$target, '331']
                : ['331', $target];

            $lineIds = $groupRows->pluck('line_id')->implode(',');
            $jeCodes = $groupRows->pluck('je_code')->unique()->implode(',');

            $preview[] = [
                $first->supplier_name ?? '(không xác định)',
                $drAccount, $crAccount,
                number_format($total),
                $groupRows->count(),
            ];

            if (!$isDry) {
                $notes = "Gộp điều chỉnh TK 331→{$target} | JEs: {$jeCodes} | line_id:{$lineIds}";
                DB::transaction(function () use (
                    $today, $period, $target, $first, $drAccount, $crAccount, $total, $notes, $jeCodes
                ) {
                    $entry = JournalEntry::create([
                        'code'                         => JournalEntry::generateCode(),
                        'entry_date'                   => $today,
                        'description'                  => "Điều chỉnh phân loại công nợ NCC từ TK 331 sang TK {$target} theo migration 900064",
                        'reference_type'               => 'journal_entry',
                        'reference_id'                 => $first->journal_entry_id,
                        'source_type'                  => 'payable_reclassification',
                        'fiscal_period'                => $period,
                        'exclude_from_period_movement' => false,
                        'status'                       => 'posted',
                        'is_auto'                      => false,
                        'created_by'                   => auth()->id() ?? 1,
                        'posted_at'                    => now(),
                        'notes'                        => $notes,
                    ]);

                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_code'     => $drAccount,
                        'description'      => "Điều chỉnh Dr TK 331→{$target} | {$first->supplier_name} | {$jeCodes}",
                        'debit'            => $total,
                        'credit'           => 0,
                        'sort_order'       => 0,
                        'partner_type'     => $first->partner_type,
                        'partner_id'       => $first->partner_id,
                    ]);

                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_code'     => $crAccount,
                        'description'      => "Điều chỉnh Cr TK 331→{$target} | {$first->supplier_name} | {$jeCodes}",
                        'debit'            => 0,
                        'credit'           => $total,
                        'sort_order'       => 1,
                        'partner_type'     => null,
                        'partner_id'       => null,
                    ]);
                });

                $this->line("  ✓ {$first->supplier_name} → Dr {$drAccount} / Cr {$crAccount} " . number_format($total));
            }
        }

        if ($isDry) {
            $this->table($headers, $preview);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function createAdjustmentJe(
        Carbon $today,
        string $period,
        string $target,
        string $jeCode,
        int $lineId,
        int $originalJeId,
        string $drAccount,
        string $crAccount,
        int $amount,
        ?string $supplierName,
        ?string $partnerType,
        ?string $partnerId,
    ): void {
        $notes = "Điều chỉnh TK 331→{$target} từ {$jeCode} line_id:{$lineId} | Supplier: {$supplierName}";

        DB::transaction(function () use (
            $today, $period, $target, $jeCode, $originalJeId,
            $drAccount, $crAccount, $amount, $supplierName, $partnerType, $partnerId, $notes
        ) {
            $entry = JournalEntry::create([
                'code'                         => JournalEntry::generateCode(),
                'entry_date'                   => $today,
                'description'                  => "Điều chỉnh phân loại công nợ NCC từ TK 331 sang TK {$target} theo migration 900064",
                'reference_type'               => 'journal_entry',
                'reference_id'                 => $originalJeId,
                'source_type'                  => 'payable_reclassification',
                'fiscal_period'                => $period,
                'exclude_from_period_movement' => false,
                'status'                       => 'posted',
                'is_auto'                      => false,
                'created_by'                   => auth()->id() ?? 1,
                'posted_at'                    => now(),
                'notes'                        => $notes,
            ]);

            // drAccount luôn là bên Nợ; crAccount luôn là bên Có.
            // partner_type/partner_id gắn trên dòng tài khoản chi tiết (target != '331').
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_code'     => $drAccount,
                'description'      => "Điều chỉnh Dr {$drAccount} | {$jeCode}",
                'debit'            => $amount,
                'credit'           => 0,
                'sort_order'       => 0,
                'partner_type'     => $drAccount !== '331' ? $partnerType : null,
                'partner_id'       => $drAccount !== '331' ? $partnerId : null,
            ]);

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_code'     => $crAccount,
                'description'      => "Điều chỉnh Cr {$crAccount} | {$jeCode}",
                'debit'            => 0,
                'credit'           => $amount,
                'sort_order'       => 1,
                'partner_type'     => $crAccount !== '331' ? $partnerType : null,
                'partner_id'       => $crAccount !== '331' ? $partnerId : null,
            ]);
        });
    }
}
