<?php

namespace App\Console\Commands;

use App\Models\PurchaseInvoice;
use App\Services\AccountingService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sửa bút toán hóa đơn mua hàng bị hạch toán sai loại.
 *
 * Xử lý L1: HĐ hàng hóa có confirmed stock_entry nhưng còn JE từ service path.
 *   - Draft JE: xóa trực tiếp.
 *   - Posted JE (kỳ mở): tạo bút toán đảo rồi xóa liên kết.
 *   - Posted JE (kỳ khóa): tạo bút toán điều chỉnh ở kỳ hiện tại.
 *
 * Không xử lý tự động:
 *   - L2: HĐ hàng hóa thiếu NK — cần tạo NK thủ công.
 *   - L3: JE 1561 ≠ subtotal — cần kế toán xác nhận.
 *   - L4: JE 331 ≠ thanh toán — cần kế toán xác nhận.
 */
class PurchaseInvoicesFixAccounting extends Command
{
    protected $signature = 'purchase-invoices:fix-accounting
        {--apply   : Thực sự sửa dữ liệu (không có flag này = dry-run)}
        {--limit=20: Số hóa đơn tối đa xử lý mỗi lần chạy}';

    protected $description = 'Sửa bút toán hóa đơn mua hàng bị hạch toán sai loại';

    public function handle(AccountingService $accounting, PurchaseInvoiceService $invoiceService): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $tag   = $apply ? '[APPLY]' : '[DRY-RUN]';

        $this->info("=== PURCHASE INVOICE FIX ACCOUNTING {$tag} ===");
        if (!$apply) {
            $this->warn('Chế độ DRY-RUN. Thêm --apply để thực sự sửa.');
        }
        $this->line('');

        // Tìm HĐ L1: có JE service path nhưng đồng thời có confirmed stock_entry
        $invoiceIds = DB::table('purchase_invoices as pi')
            ->join('purchase_orders as po', 'po.id', '=', 'pi.purchase_order_id')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->where('je.reference_type', 'purchase_invoice')
                    ->whereColumn('je.reference_id', 'pi.id')
                    ->whereIn('je.status', ['draft', 'posted']);
            })
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('stock_entries as se')
                    ->whereColumn('se.purchase_order_id', 'po.id')
                    ->where('se.status', 'confirmed');
            })
            ->whereNotIn('pi.status', ['cancelled'])
            ->pluck('pi.id')
            ->take($limit);

        if ($invoiceIds->isEmpty()) {
            $this->info('Không phát hiện hóa đơn nào bị L1 (hàng hóa có service JE).');
            return self::SUCCESS;
        }

        $this->warn("Phát hiện {$invoiceIds->count()} hóa đơn L1:");

        $invoices = PurchaseInvoice::with(['purchaseOrder', 'supplier'])->whereIn('id', $invoiceIds)->get();

        $fixed    = 0;
        $skipped  = 0;
        $manual   = [];

        foreach ($invoices as $invoice) {
            $je = DB::table('journal_entries')
                ->where('reference_type', 'purchase_invoice')
                ->where('reference_id', $invoice->id)
                ->whereIn('status', ['draft', 'posted'])
                ->first();

            if (!$je) { $skipped++; continue; }

            $this->line("  HĐ {$invoice->code} — JE #{$je->id} status={$je->status}");

            if (!$apply) {
                $action = $je->status === 'draft' ? 'XÓA draft JE' : 'ĐẢO posted JE rồi gỡ liên kết';
                $this->line("  → [DRY-RUN] Sẽ: {$action}");
                continue;
            }

            // Draft: xóa trực tiếp
            if ($je->status === 'draft') {
                DB::table('journal_entry_lines')->where('journal_entry_id', $je->id)->delete();
                DB::table('journal_entries')->where('id', $je->id)->delete();
                $this->info("  ✓ Đã xóa draft JE #{$je->id}");
                $fixed++;
                continue;
            }

            // Posted: đảo JE qua AccountingService
            try {
                $accounting->reverseOrDelete('purchase_invoice', $invoice->id, "Fix: hóa đơn hàng hóa bị hạch toán nhầm service path — {$invoice->code}");
                $this->info("  ✓ Đã đảo JE #{$je->id} cho HĐ {$invoice->code}");
                $fixed++;
            } catch (\Throwable $e) {
                $this->error("  ✗ Không thể đảo JE #{$je->id}: {$e->getMessage()}");
                $manual[] = $invoice->code;
                $skipped++;
            }
        }

        $this->line('');
        if ($apply) {
            $this->info("Hoàn thành: đã sửa {$fixed}, bỏ qua {$skipped}.");
            if (!empty($manual)) {
                $this->warn('Cần xử lý thủ công (kỳ khóa hoặc lỗi): ' . implode(', ', $manual));
            }
        } else {
            $this->line("Sẽ xử lý: {$invoiceIds->count()} hóa đơn. Chạy lại với --apply để thực hiện.");
        }

        return self::SUCCESS;
    }
}
