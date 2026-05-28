<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'accounting:mark-overdue {--dry-run : Chỉ liệt kê, không thay đổi}';
    protected $description = 'Tự động chuyển hóa đơn quá hạn thanh toán từ Sent → Overdue';

    public function handle(): int
    {
        $today = now()->toDateString();

        $query = Invoice::where('status', InvoiceStatus::Sent)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today);

        $count = $query->count();

        if ($count === 0) {
            $this->info('Không có hóa đơn quá hạn cần cập nhật.');
            return 0;
        }

        if ($this->option('dry-run')) {
            $this->warn("[dry-run] {$count} hóa đơn sẽ bị đánh dấu quá hạn:");
            $query->get(['id', 'code', 'due_date'])->each(fn ($inv) =>
                $this->line("  - {$inv->code} (hạn: {$inv->due_date})")
            );
            return 0;
        }

        $updated = $query->update(['status' => InvoiceStatus::Overdue->value]);
        $this->info("Đã cập nhật {$updated} hóa đơn sang trạng thái Quá hạn.");

        return 0;
    }
}
