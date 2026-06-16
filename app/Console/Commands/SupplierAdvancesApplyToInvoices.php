<?php

namespace App\Console\Commands;

use App\Models\PurchaseInvoice;
use App\Models\SupplierOpeningAdvance;
use App\Services\SupplierAdvanceService;
use Illuminate\Console\Command;

class SupplierAdvancesApplyToInvoices extends Command
{
    protected $signature = 'supplier-advances:apply-to-invoices
        {--year= : Năm tài chính (mặc định: năm hiện tại)}
        {--dry-run : Chỉ xem đề xuất, không lưu}
        {--supplier= : Mã NCC cụ thể (nếu muốn áp dụng cho một NCC)}';

    protected $description = 'Tự động đề xuất đối trừ ứng trước đầu kỳ với hóa đơn mua hàng';

    public function handle(): int
    {
        $year       = (int) ($this->option('year') ?? now()->year);
        $dryRun     = (bool) $this->option('dry-run');
        $supplierCode = $this->option('supplier');

        $this->info("Đề xuất đối trừ ứng trước năm {$year}" . ($dryRun ? ' [DRY RUN]' : ''));

        // Lấy danh sách ứng trước còn dư
        $advances = SupplierOpeningAdvance::where('fiscal_year', $year)
            ->whereIn('status', ['open', 'partially_applied'])
            ->where('remaining_amount', '>', 0)
            ->when($supplierCode, fn ($q) =>
                $q->whereHas('supplier', fn ($q2) => $q2->where('code', $supplierCode))
            )
            ->with('supplier')
            ->orderBy('supplier_id')
            ->orderBy('opening_date')
            ->get();

        if ($advances->isEmpty()) {
            $this->warn("Không có ứng trước còn dư cho năm {$year}.");
            return 0;
        }

        $proposals = [];

        foreach ($advances as $advance) {
            // Lấy hóa đơn chưa trả hết của NCC này
            $invoices = PurchaseInvoice::where('supplier_id', $advance->supplier_id)
                ->whereIn('status', ['valid', 'partial_paid'])
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->get();

            $remaining = (float) $advance->remaining_amount;

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) break;

                $invoiceDue = (float) $invoice->total
                    - (float) $invoice->paid_amount
                    - (float) $invoice->advance_allocated_amount;

                if ($invoiceDue <= 0) continue;

                $allocate = min($remaining, $invoiceDue);
                $remaining -= $allocate;

                $proposals[] = [
                    'supplier'           => $advance->supplier->name,
                    'advance_id'         => $advance->id,
                    'advance_ref'        => $advance->reference_no ?? ('ADV-' . $advance->id),
                    'advance_remaining'  => (float) $advance->remaining_amount,
                    'invoice_id'         => $invoice->id,
                    'invoice_code'       => $invoice->code,
                    'invoice_due'        => $invoiceDue,
                    'allocate'           => $allocate,
                    'adv_obj'            => $advance,
                    'inv_obj'            => $invoice,
                ];
            }
        }

        if (empty($proposals)) {
            $this->info("Không có đề xuất đối trừ phù hợp.");
            return 0;
        }

        $this->line('');
        $this->info('Đề xuất đối trừ:');
        $this->table(
            ['NCC', 'Ứng trước', 'Hóa đơn', 'Còn phải trả HĐ', 'Đề xuất đối trừ'],
            collect($proposals)->map(fn ($p) => [
                $p['supplier'],
                $p['advance_ref'],
                $p['invoice_code'],
                number_format($p['invoice_due']),
                number_format($p['allocate']),
            ])
        );

        $totalAllocate = array_sum(array_column($proposals, 'allocate'));
        $this->line('');
        $this->info("Tổng đề xuất đối trừ: " . number_format($totalAllocate) . " đ");

        if ($dryRun) {
            $this->warn('[DRY RUN] Không lưu. Chạy lại không có --dry-run để áp dụng.');
            return 0;
        }

        if (!$this->confirm('Xác nhận áp dụng ' . count($proposals) . ' đối trừ?')) {
            $this->line('Đã hủy.');
            return 0;
        }

        $service = app(SupplierAdvanceService::class);
        // Use first admin user as actor
        auth()->loginUsingId(1);

        $success = 0;
        $errors  = 0;

        foreach ($proposals as $p) {
            try {
                $p['adv_obj']->refresh();
                $p['inv_obj']->refresh();

                $service->allocate(
                    $p['adv_obj'],
                    $p['inv_obj'],
                    $p['allocate'],
                    now()->toDateString(),
                    "Tự động đối trừ ứng trước đầu kỳ {$year} qua artisan command"
                );
                $success++;
            } catch (\Exception $e) {
                $this->error("Lỗi đối trừ {$p['invoice_code']}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->info("Đã áp dụng: {$success} đối trừ. Lỗi: {$errors}.");
        return $errors > 0 ? 1 : 0;
    }
}
