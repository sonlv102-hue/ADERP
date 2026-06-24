<?php

namespace App\Console\Commands;

use App\Models\SupplierAdvanceRefund;
use App\Models\SupplierOpeningAdvance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SupplierAdvancesAudit extends Command
{
    protected $signature = 'supplier-advances:audit
        {--from= : Ngày bắt đầu (Y-m-d)}
        {--to=   : Ngày kết thúc (Y-m-d)}
        {--fix   : Tự sửa remaining_amount không khớp}';

    protected $description = 'Kiểm tra tính toàn vẹn dữ liệu Tiền trả trước NCC (330UT).';

    private int $errors = 0;

    public function handle(): int
    {
        $from = $this->option('from') ?? '2000-01-01';
        $to   = $this->option('to')   ?? now()->toDateString();
        $fix  = $this->option('fix');

        $this->info("=== Supplier Advances Audit ($from → $to) ===");

        $advances = SupplierOpeningAdvance::whereBetween('opening_date', [$from, $to])->get();
        $this->info("Tổng khoản: {$advances->count()}");

        $this->check1_remainingConsistency($advances, $fix);
        $this->check2_cancelledHasNoAllocations($advances);
        $this->check3_fullyAppliedHasZeroRemaining($advances);
        $this->check4_openHasPositiveRemaining($advances);
        $this->check5_refundNotExceedAmount($advances);
        $this->check6_allocationNotExceedAmount($advances);
        $this->check7_cancelledHasNoRefunds($advances);
        $this->check8_refundJeExists();
        $this->check9_orphanRefunds($advances);
        $this->check10_duplicateAllocations();

        $this->newLine();
        if ($this->errors === 0) {
            $this->info('✅ Tất cả kiểm tra đều OK.');
        } else {
            $this->error("❌ Phát hiện {$this->errors} vấn đề.");
        }

        return $this->errors > 0 ? 1 : 0;
    }

    private function check1_remainingConsistency($advances, bool $fix): void
    {
        $this->info('[1] remaining_amount = amount - active_allocations - confirmed_refunds');
        $mismatches = 0;

        foreach ($advances as $adv) {
            $activeAlloc = DB::table('supplier_advance_allocations')
                ->where('opening_advance_id', $adv->id)
                ->where('status', 'active')
                ->sum('allocated_amount');

            $refunded = DB::table('supplier_advance_refunds')
                ->where('supplier_advance_id', $adv->id)
                ->where('status', 'confirmed')
                ->sum('amount');

            $expected = round((float) $adv->amount - $activeAlloc - $refunded, 2);
            $actual   = round((float) $adv->remaining_amount, 2);

            if (abs($expected - $actual) > 0.01) {
                $this->warn("  [#{$adv->id}] expected={$expected}, actual={$actual}, diff=" . ($expected - $actual));
                $mismatches++;
                $this->errors++;

                if ($fix) {
                    $adv->update(['remaining_amount' => $expected]);
                    $this->line("    → FIXED remaining_amount = {$expected}");
                }
            }
        }

        if ($mismatches === 0) $this->line('   OK');
    }

    private function check2_cancelledHasNoAllocations($advances): void
    {
        $this->info('[2] Khoản đã hủy không có active allocations');
        $cancelled = $advances->where('status', 'cancelled');

        foreach ($cancelled as $adv) {
            $count = DB::table('supplier_advance_allocations')
                ->where('opening_advance_id', $adv->id)->where('status', 'active')->count();
            if ($count > 0) {
                $this->warn("  [#{$adv->id}] Đã hủy nhưng còn {$count} active allocations.");
                $this->errors++;
            }
        }

        $this->line('   OK');
    }

    private function check3_fullyAppliedHasZeroRemaining($advances): void
    {
        $this->info('[3] fully_applied phải có remaining_amount <= 0');
        foreach ($advances->where('status', 'fully_applied') as $adv) {
            if ((float) $adv->remaining_amount > 0.01) {
                $this->warn("  [#{$adv->id}] status=fully_applied nhưng remaining={$adv->remaining_amount}");
                $this->errors++;
            }
        }
        $this->line('   OK');
    }

    private function check4_openHasPositiveRemaining($advances): void
    {
        $this->info('[4] open / partially_applied phải có remaining_amount > 0');
        foreach ($advances->whereIn('status', ['open', 'partially_applied']) as $adv) {
            if ((float) $adv->remaining_amount <= 0) {
                $this->warn("  [#{$adv->id}] status={$adv->status} nhưng remaining={$adv->remaining_amount}");
                $this->errors++;
            }
        }
        $this->line('   OK');
    }

    private function check5_refundNotExceedAmount($advances): void
    {
        $this->info('[5] Tổng refunds không vượt quá amount');
        foreach ($advances as $adv) {
            $totalRefunded = DB::table('supplier_advance_refunds')
                ->where('supplier_advance_id', $adv->id)->where('status', 'confirmed')->sum('amount');
            if ((float) $totalRefunded > (float) $adv->amount + 0.01) {
                $this->warn("  [#{$adv->id}] total_refunded={$totalRefunded} > amount={$adv->amount}");
                $this->errors++;
            }
        }
        $this->line('   OK');
    }

    private function check6_allocationNotExceedAmount($advances): void
    {
        $this->info('[6] Tổng active_allocations + refunds không vượt quá amount');
        foreach ($advances as $adv) {
            $alloc   = DB::table('supplier_advance_allocations')
                ->where('opening_advance_id', $adv->id)->where('status', 'active')->sum('allocated_amount');
            $refund  = DB::table('supplier_advance_refunds')
                ->where('supplier_advance_id', $adv->id)->where('status', 'confirmed')->sum('amount');
            $total   = round($alloc + $refund, 2);
            if ($total > (float) $adv->amount + 0.01) {
                $this->warn("  [#{$adv->id}] alloc+refund={$total} > amount={$adv->amount}");
                $this->errors++;
            }
        }
        $this->line('   OK');
    }

    private function check7_cancelledHasNoRefunds($advances): void
    {
        $this->info('[7] Khoản đã hủy không có confirmed refunds');
        foreach ($advances->where('status', 'cancelled') as $adv) {
            $count = DB::table('supplier_advance_refunds')
                ->where('supplier_advance_id', $adv->id)->where('status', 'confirmed')->count();
            if ($count > 0) {
                $this->warn("  [#{$adv->id}] Đã hủy nhưng có {$count} confirmed refund(s).");
                $this->errors++;
            }
        }
        $this->line('   OK');
    }

    private function check8_refundJeExists(): void
    {
        $this->info('[8] Mọi refund phải có journal_entry_id hợp lệ');
        $orphans = SupplierAdvanceRefund::where('status', 'confirmed')
            ->where(fn ($q) => $q->whereNull('journal_entry_id')
                ->orWhereDoesntHave('journalEntry'))
            ->count();
        if ($orphans > 0) {
            $this->warn("  {$orphans} refund(s) không có JE hợp lệ.");
            $this->errors += $orphans;
        } else {
            $this->line('   OK');
        }
    }

    private function check9_orphanRefunds($advances): void
    {
        $this->info('[9] Refunds thuộc advance tồn tại và đúng supplier_id');
        $advIds = $advances->pluck('id')->all();

        $orphans = DB::table('supplier_advance_refunds')
            ->whereNotIn('supplier_advance_id', $advIds)
            ->count();
        if ($orphans > 0) {
            $this->warn("  {$orphans} refund(s) trỏ tới advance không nằm trong phạm vi lọc (bình thường nếu lọc date hẹp).");
        }
        $this->line('   OK');
    }

    private function check10_duplicateAllocations(): void
    {
        $this->info('[10] Không có allocation trùng (cùng advance + invoice + active)');
        $dupes = DB::table('supplier_advance_allocations')
            ->select('opening_advance_id', 'purchase_invoice_id', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'active')
            ->whereNotNull('purchase_invoice_id')
            ->groupBy('opening_advance_id', 'purchase_invoice_id')
            ->having('cnt', '>', 1)
            ->count();

        if ($dupes > 0) {
            $this->warn("  {$dupes} cặp advance+invoice bị đối trừ trùng.");
            $this->errors += $dupes;
        } else {
            $this->line('   OK');
        }
    }
}
