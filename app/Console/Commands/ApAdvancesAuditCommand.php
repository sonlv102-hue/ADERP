<?php

namespace App\Console\Commands;

use App\Models\AccountCode;
use App\Models\JournalEntryLine;
use App\Models\SupplierAdvanceAllocation;
use App\Models\SupplierOpeningAdvance;
use App\Services\AccountingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra tính nhất quán của khoản ứng trước NCC (TK 331UT).
 *
 * Checks:
 *  A1 — remaining_amount không khớp tổng allocation active
 *  A2 — allocation không có JE trong khi advance.account_code ≠ payable
 *  A3 — JE tồn tại nhưng sai TK (Dr không phải payable, Cr không phải advance account)
 *  A4 — invoice.advance_allocated_amount không khớp tổng allocation active của HĐ đó
 *  A5 — advance.account_code = '3311' (nên là 331UT sau migration 900131)
 *  A6 — 331UT parent hierarchy sai (parent_code nên là '331', không phải '3311')
 *  A7 — có bút toán ghi trực tiếp vào TK cha '331' (is_detail=false)
 *  A8 — allocation cross-supplier (advance.supplier_id ≠ invoice.supplier_id)
 */
class ApAdvancesAuditCommand extends Command
{
    protected $signature = 'ap:advances-audit
                            {--dry-run : Chỉ báo cáo, không sửa gì}
                            {--supplier= : Lọc theo supplier_id}';

    protected $description = 'Kiểm tra tính nhất quán ứng trước NCC (TK 331UT, JE, remaining, hierarchy).';

    private array $issues = [];

    public function handle(): int
    {
        $dryRun     = $this->option('dry-run') || true; // Audit only — no writes
        $supplierId = $this->option('supplier') ? (int) $this->option('supplier') : null;

        $this->info('Đang rà soát ứng trước NCC...');

        $this->checkA1RemainMismatch($supplierId);
        $this->checkA2MissingJe($supplierId);
        $this->checkA3WrongJeAccounts($supplierId);
        $this->checkA4InvoiceAllocatedMismatch($supplierId);
        $this->checkA5WrongAccountCode($supplierId);
        $this->checkA6HierarchyIssues();
        $this->checkA7DirectPostingToParent();
        $this->checkA8CrossSupplier($supplierId);

        if (empty($this->issues)) {
            $this->info('✓ Không phát hiện vấn đề nào.');
            return Command::SUCCESS;
        }

        $critical = count(array_filter($this->issues, fn($i) => $i['level'] === 'critical'));
        $warning  = count(array_filter($this->issues, fn($i) => $i['level'] === 'warning'));

        $this->newLine();
        $this->error("  Tổng: {$critical} lỗi nghiêm trọng, {$warning} cảnh báo  ");
        $this->newLine();

        $grouped = collect($this->issues)->groupBy('check');

        foreach ($grouped as $check => $group) {
            $level = $group->first()['level'];
            $label = $level === 'critical' ? "<fg=red>[CRITICAL]</>" : "<fg=yellow>[WARNING]</>";
            $this->line("{$label} {$check} — {$group->count()} vấn đề");

            $rows = $group->map(fn($i) => [$i['id'] ?? '—', $i['detail']])->toArray();
            $this->table(['ID', 'Chi tiết'], $rows);
            $this->newLine();
        }

        return Command::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function addIssue(string $check, string $level, string|int|null $id, string $detail): void
    {
        $this->issues[] = compact('check', 'level', 'id', 'detail');
    }

    // A1: remaining_amount trên advance ≠ amount - sum(active allocations)
    private function checkA1RemainMismatch(?int $supplierId): void
    {
        $advances = SupplierOpeningAdvance::when($supplierId, fn($q) => $q->where('supplier_id', $supplierId))
            ->whereNotIn('status', ['cancelled'])
            ->withSum(['allocations as total_allocated' => fn($q) => $q->where('status', 'active')], 'allocated_amount')
            ->get();

        foreach ($advances as $adv) {
            $expectedRemaining = (float) $adv->amount - (float) ($adv->total_allocated ?? 0);
            $actualRemaining   = (float) $adv->remaining_amount;

            if (abs($expectedRemaining - $actualRemaining) > 1) {
                $this->addIssue(
                    'A1:remaining_mismatch',
                    'critical',
                    $adv->id,
                    "Advance #{$adv->id} remaining={$actualRemaining} nhưng amount-allocated={$expectedRemaining}"
                );
            }
        }
    }

    // A2: allocation không có JE trong khi advance.account ≠ payable
    private function checkA2MissingJe(?int $supplierId): void
    {
        $defaultAdvanceAccount = AccountingSettings::get('supplier_advance_account', '331UT');

        $allocations = SupplierAdvanceAllocation::with(['advance.supplier'])
            ->where('status', 'active')
            ->whereNull('journal_entry_id')
            ->when($supplierId, fn($q) => $q->whereHas('advance', fn($q2) => $q2->where('supplier_id', $supplierId)))
            ->get();

        foreach ($allocations as $al) {
            $advance        = $al->advance;
            $advanceAccount = $advance->account_code ?? $defaultAdvanceAccount;
            $supplier       = $advance->supplier;

            if (!$supplier) continue;

            $payableAccount = $supplier->payable_account_code ?? '3311';

            if ($advanceAccount !== $payableAccount) {
                $this->addIssue(
                    'A2:missing_je',
                    'critical',
                    $al->id,
                    "Allocation #{$al->id} (advance #{$advance->id}, account={$advanceAccount}) không có JE nhưng advance≠payable ({$payableAccount})"
                );
            }
        }
    }

    // A3: JE tồn tại nhưng sai TK (Dr không phải payable, Cr không phải advance account)
    private function checkA3WrongJeAccounts(?int $supplierId): void
    {
        $defaultAdvanceAccount = AccountingSettings::get('supplier_advance_account', '331UT');

        $allocations = SupplierAdvanceAllocation::with(['advance.supplier'])
            ->where('status', 'active')
            ->whereNotNull('journal_entry_id')
            ->when($supplierId, fn($q) => $q->whereHas('advance', fn($q2) => $q2->where('supplier_id', $supplierId)))
            ->get();

        foreach ($allocations as $al) {
            $advance        = $al->advance;
            $advanceAccount = $advance->account_code ?? $defaultAdvanceAccount;
            $supplier       = $advance->supplier;
            if (!$supplier) continue;

            $payableAccount = $supplier->payable_account_code ?? '3311';
            $lines          = JournalEntryLine::where('journal_entry_id', $al->journal_entry_id)->get();
            $drLine         = $lines->first(fn($l) => $l->debit > 0);
            $crLine         = $lines->first(fn($l) => $l->credit > 0);

            if ($drLine && $drLine->account_code !== $payableAccount) {
                $this->addIssue(
                    'A3:wrong_je_dr',
                    'critical',
                    $al->id,
                    "Allocation #{$al->id} JE Dr={$drLine->account_code} nhưng mong đợi {$payableAccount}"
                );
            }
            if ($crLine && $crLine->account_code !== $advanceAccount) {
                $this->addIssue(
                    'A3:wrong_je_cr',
                    'critical',
                    $al->id,
                    "Allocation #{$al->id} JE Cr={$crLine->account_code} nhưng mong đợi {$advanceAccount}"
                );
            }
        }
    }

    // A4: invoice.advance_allocated_amount ≠ sum của active allocations trên HĐ đó
    private function checkA4InvoiceAllocatedMismatch(?int $supplierId): void
    {
        $rows = DB::table('supplier_advance_allocations as al')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'al.purchase_invoice_id')
            ->join('supplier_opening_advances as soa', 'soa.id', '=', 'al.opening_advance_id')
            ->when($supplierId, fn($q) => $q->where('soa.supplier_id', $supplierId))
            ->where('al.status', 'active')
            ->whereNotNull('al.purchase_invoice_id')
            ->groupBy('pi.id', 'pi.code', 'pi.advance_allocated_amount')
            ->select([
                'pi.id',
                'pi.code',
                'pi.advance_allocated_amount',
                DB::raw('SUM(al.allocated_amount) as computed'),
            ])
            ->get();

        foreach ($rows as $row) {
            $diff = abs((float) $row->advance_allocated_amount - (float) $row->computed);
            if ($diff > 1) {
                $this->addIssue(
                    'A4:invoice_allocated_mismatch',
                    'critical',
                    $row->id,
                    "HĐ {$row->code} advance_allocated_amount={$row->advance_allocated_amount} nhưng sum allocations={$row->computed}"
                );
            }
        }
    }

    // A5: advance.account_code = '3311' (nên là 331UT sau migration 900131)
    private function checkA5WrongAccountCode(?int $supplierId): void
    {
        $wrong = SupplierOpeningAdvance::when($supplierId, fn($q) => $q->where('supplier_id', $supplierId))
            ->where('account_code', '3311')
            ->whereNotIn('status', ['cancelled'])
            ->get();

        foreach ($wrong as $adv) {
            $this->addIssue(
                'A5:wrong_account_code',
                'warning',
                $adv->id,
                "Advance #{$adv->id} (NCC={$adv->supplier_id}) account_code='3311' — nên là '331UT' (chạy migration 900131)"
            );
        }
    }

    // A6: 331UT parent hierarchy (parent_code nên là '331', không phải '3311')
    private function checkA6HierarchyIssues(): void
    {
        $ut = AccountCode::where('code', '331UT')->first();
        if (!$ut) {
            $this->addIssue('A6:hierarchy', 'warning', '331UT', "TK '331UT' chưa tồn tại trong account_codes");
            return;
        }

        if ($ut->parent_code !== '331') {
            $this->addIssue(
                'A6:hierarchy',
                'critical',
                '331UT',
                "331UT.parent_code='{$ut->parent_code}' — phải là '331'"
            );
        }

        if (!$ut->is_detail) {
            $this->addIssue('A6:hierarchy', 'critical', '331UT', "331UT.is_detail=false — phải là true");
        }

        $detail3311 = AccountCode::where('code', '3311')->first();
        if ($detail3311 && $detail3311->parent_code !== '331') {
            $this->addIssue(
                'A6:hierarchy',
                'warning',
                '3311',
                "3311.parent_code='{$detail3311->parent_code}' — nên là '331'"
            );
        }
    }

    // A7: có bút toán ghi trực tiếp vào TK cha '331' (is_detail=false)
    private function checkA7DirectPostingToParent(): void
    {
        $parentCodes = AccountCode::where('is_detail', false)
            ->whereBetween('code', ['331', '3319'])
            ->pluck('code');

        if ($parentCodes->isEmpty()) return;

        $directPostings = DB::table('journal_entry_lines')
            ->whereIn('account_code', $parentCodes)
            ->select('account_code', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(debit+credit) as total'))
            ->groupBy('account_code')
            ->get();

        foreach ($directPostings as $row) {
            $this->addIssue(
                'A7:direct_parent_posting',
                'critical',
                $row->account_code,
                "TK '{$row->account_code}' (TK tổng hợp) có {$row->cnt} dòng bút toán trực tiếp (total=" . number_format($row->total) . ")"
            );
        }
    }

    // A8: allocation cross-supplier (advance.supplier_id ≠ invoice.supplier_id)
    private function checkA8CrossSupplier(?int $supplierId): void
    {
        $rows = DB::table('supplier_advance_allocations as al')
            ->join('supplier_opening_advances as soa', 'soa.id', '=', 'al.opening_advance_id')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'al.purchase_invoice_id')
            ->when($supplierId, fn($q) => $q->where('soa.supplier_id', $supplierId))
            ->whereColumn('soa.supplier_id', '!=', 'pi.supplier_id')
            ->where('al.status', 'active')
            ->select('al.id', 'soa.supplier_id as adv_supplier', 'pi.supplier_id as inv_supplier', 'pi.code as invoice_code')
            ->get();

        foreach ($rows as $row) {
            $this->addIssue(
                'A8:cross_supplier',
                'critical',
                $row->id,
                "Allocation #{$row->id}: advance thuộc NCC {$row->adv_supplier} nhưng HĐ {$row->invoice_code} thuộc NCC {$row->inv_supplier}"
            );
        }
    }
}
