<?php

namespace App\Console\Commands;

use App\Models\CashVoucher;
use App\Models\JournalEntry;
use App\Models\SupplierOpeningAdvance;
use App\Services\SupplierAdvanceService;
use Illuminate\Console\Command;

class SupplierAdvancesDeleteCommand extends Command
{
    protected $signature = 'supplier-advances:delete
                            {--id=      : ID khoản trả trước cần xóa}
                            {--supplier= : Lọc theo tên NCC (chỉ dùng với --dry-run)}
                            {--dry-run  : Kiểm tra điều kiện, không thực sự xóa}
                            {--apply    : Xóa thật (bắt buộc sau khi đã dry-run)}
                            {--reason=  : Lý do xóa}';

    protected $description = 'Xóa mềm khoản trả trước NCC — dry-run trước, apply sau khi xác nhận.';

    public function __construct(private SupplierAdvanceService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isApply  = $this->option('apply');
        $id       = $this->option('id');
        $supplier = $this->option('supplier');
        $reason   = $this->option('reason');

        if (!$isDryRun && !$isApply) {
            $this->error('Phải truyền --dry-run hoặc --apply.');
            return 1;
        }

        if ($isApply && !$id) {
            $this->error('--apply yêu cầu --id cụ thể.');
            return 1;
        }

        $query = SupplierOpeningAdvance::with(['supplier', 'activeAllocations']);

        if ($id) {
            $query->where('id', $id);
        } elseif ($supplier) {
            $query->whereHas('supplier', fn ($q) => $q->where('name', 'ilike', "%{$supplier}%"));
        } else {
            $this->error('Phải truyền --id hoặc --supplier.');
            return 1;
        }

        $advances = $query->get();

        if ($advances->isEmpty()) {
            $this->warn('Không tìm thấy khoản nào phù hợp.');
            return 0;
        }

        foreach ($advances as $adv) {
            $this->printAudit($adv);
        }

        if ($isDryRun) {
            $this->info('Dry-run hoàn thành. Chạy lại với --apply để thực sự xóa.');
            return 0;
        }

        // Apply
        $adv = $advances->first();
        if (!$this->confirm("Xác nhận XÓA khoản #{$adv->id} ({$adv->supplier->name}, {$adv->amount} đ, {$adv->status})?")) {
            $this->info('Đã hủy.');
            return 0;
        }

        try {
            // Auth fake cho console
            if (!auth()->check()) {
                $user = \App\Models\User::where('email', 'admin@minierp.local')->first()
                    ?? \App\Models\User::first();
                auth()->login($user);
            }

            $this->service->deleteSafely($adv, $reason);
            $this->info("✓ Đã xóa mềm khoản #{$adv->id}.");
        } catch (\RuntimeException $e) {
            $this->error("Lỗi: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    private function printAudit(SupplierOpeningAdvance $adv): void
    {
        $hasActiveAlloc = $adv->activeAllocations->isNotEmpty();
        $allAllocCount  = $adv->allocations()->count();

        $voucher = CashVoucher::where('reference_type', SupplierOpeningAdvance::class)
            ->where('reference_id', $adv->id)->first();

        $je = JournalEntry::where('reference_type', 'cash_voucher')
            ->where('reference_id', optional($voucher)->id)
            ->first();

        $canDelete = in_array($adv->status, ['open', 'cancelled']) && !$hasActiveAlloc
            && !in_array($adv->status, ['fully_applied', 'partially_applied']);

        $action = match(true) {
            !$canDelete && $hasActiveAlloc              => 'BLOCK — còn đối trừ active',
            !$canDelete                                 => 'BLOCK — trạng thái ' . $adv->status,
            $adv->status === 'cancelled'                => 'soft_delete (không cần đảo JE)',
            $voucher && $voucher->status === 'confirmed' => 'cancel_voucher + đảo JE → soft_delete',
            default                                     => 'soft_delete',
        };

        $this->line('');
        $this->info("=== Advance #" . $adv->id . " ===");
        $this->line("NCC          : " . $adv->supplier->name);
        $this->line("Ngày         : " . $adv->opening_date->format('d/m/Y'));
        $this->line("Số tiền      : " . number_format($adv->amount, 0, ',', '.') . " đ");
        $this->line("Còn lại      : " . number_format($adv->remaining_amount, 0, ',', '.') . " đ");
        $this->line("Trạng thái   : " . $adv->status);
        $this->line("Loại         : " . $adv->advance_type);
        $this->line("Allocations  : {$allAllocCount} tổng / {$adv->activeAllocations->count()} active");
        $this->line("Phiếu chi    : " . ($voucher ? "{$voucher->code} ({$voucher->status->value})" : '—'));
        $this->line("Bút toán JE  : " . ($je ? "#{$je->id} {$je->code} ({$je->status})" : '—'));
        $this->line("can_delete   : " . ($canDelete ? 'YES' : 'NO'));
        ($canDelete ? $this->info("action       : {$action}") : $this->error("action       : {$action}"));
    }
}
