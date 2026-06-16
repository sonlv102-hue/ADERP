<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\SupplierOpeningAdvance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SupplierAdvancesAuditOpening extends Command
{
    protected $signature = 'supplier-advances:audit-opening {--year= : Năm tài chính cần kiểm tra (mặc định: năm hiện tại)}';
    protected $description = 'Kiểm tra NCC có ứng trước đầu kỳ chưa nhập hệ thống';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?? now()->year);
        $this->info("Kiểm tra ứng trước đầu kỳ năm {$year}...");

        // Tìm NCC đã có ứng trước được nhập
        $existingSupplierIds = SupplierOpeningAdvance::where('fiscal_year', $year)
            ->whereNot('status', 'cancelled')
            ->pluck('supplier_id')
            ->unique();

        // Danh sách ứng trước đang open/partially applied
        $advances = SupplierOpeningAdvance::where('fiscal_year', $year)
            ->whereIn('status', ['open', 'partially_applied', 'fully_applied'])
            ->with('supplier')
            ->orderBy('supplier_id')
            ->get();

        if ($advances->isEmpty()) {
            $this->warn("Chưa có khoản ứng trước đầu kỳ nào được nhập cho năm {$year}.");
            $this->line('Dùng lệnh supplier-advances:import-opening để nhập số dư.');
            return 0;
        }

        $this->info("\nDanh sách ứng trước đầu kỳ năm {$year}:");
        $this->table(
            ['ID', 'Nhà cung cấp', 'Ngày mở', 'Số tiền', 'Còn lại', 'Trạng thái', 'Tham chiếu'],
            $advances->map(fn ($a) => [
                $a->id,
                $a->supplier?->name ?? '?',
                $a->opening_date->format('d/m/Y'),
                number_format($a->amount, 0, ',', '.'),
                number_format($a->remaining_amount, 0, ',', '.'),
                $a->status,
                $a->reference_no ?? '—',
            ])
        );

        $totalOriginal  = $advances->sum('amount');
        $totalRemaining = $advances->sum('remaining_amount');
        $totalUsed      = $totalOriginal - $totalRemaining;

        $this->line('');
        $this->info("Tổng ứng trước: " . number_format($totalOriginal) . " đ");
        $this->info("Đã đối trừ:     " . number_format($totalUsed) . " đ");
        $this->info("Còn lại:        " . number_format($totalRemaining) . " đ");

        // Hóa đơn năm nay của các NCC có ứng trước chưa hoàn toàn đối trừ
        $pendingSuppliers = $advances->where('status', '!=', 'fully_applied')->pluck('supplier_id');

        if ($pendingSuppliers->isNotEmpty()) {
            $unpaidInvoices = DB::table('purchase_invoices')
                ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
                ->whereIn('purchase_invoices.supplier_id', $pendingSuppliers)
                ->whereIn('purchase_invoices.status', ['valid', 'partial_paid'])
                ->select([
                    'purchase_invoices.id',
                    'purchase_invoices.code',
                    'suppliers.name as supplier',
                    'purchase_invoices.invoice_date',
                    'purchase_invoices.total',
                    'purchase_invoices.paid_amount',
                    'purchase_invoices.advance_allocated_amount',
                ])
                ->get();

            if ($unpaidInvoices->isNotEmpty()) {
                $this->line('');
                $this->warn("Hóa đơn chưa thanh toán đầy đủ từ NCC có ứng trước:");
                $this->table(
                    ['Hóa đơn', 'NCC', 'Ngày', 'Tổng', 'Đã TT', 'Đối trừ UT', 'Còn lại'],
                    $unpaidInvoices->map(fn ($i) => [
                        $i->code,
                        $i->supplier,
                        $i->invoice_date,
                        number_format($i->total),
                        number_format($i->paid_amount),
                        number_format($i->advance_allocated_amount),
                        number_format($i->total - $i->paid_amount - $i->advance_allocated_amount),
                    ])
                );
                $this->line('');
                $this->warn("Dùng lệnh supplier-advances:apply-to-invoices --year={$year} --dry-run để xem đề xuất đối trừ.");
            }
        }

        return 0;
    }
}
