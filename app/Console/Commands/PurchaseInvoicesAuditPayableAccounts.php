<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra hóa đơn đầu vào dịch vụ bị ghi Có sai tài khoản công nợ.
 *
 * Sai điển hình:
 *   S1 – HĐ loại dịch vụ (invoice_type = service/expense/project) nhưng JE Có 3311 (phải là 3312).
 *   S2 – HĐ có items TK Nợ 154/641/642 nhưng JE Có 3311.
 *   S3 – HĐ loại hàng hóa nhưng JE Có 3312 (không nhất quán).
 *
 * Chỉ audit — không sửa dữ liệu. Nếu kỳ chưa khóa, dùng --suggest để xem lệnh reverse đề xuất.
 */
class PurchaseInvoicesAuditPayableAccounts extends Command
{
    protected $signature = 'purchase-invoices:audit-payable-accounts
        {--scope=all    : all | service | goods}
        {--limit=100    : Số dòng tối đa mỗi check}
        {--suggest      : Hiển thị lệnh reverse/rebuild JE đề xuất}';

    protected $description = 'Kiểm tra HĐ đầu vào hàng hóa/dịch vụ bị ghi Có sai TK công nợ (3311 vs 3312)';

    private int $totalIssues = 0;

    public function handle(): int
    {
        $scope = $this->option('scope');
        $limit = (int) $this->option('limit');

        $this->info('=== PURCHASE INVOICE PAYABLE ACCOUNT AUDIT ===');
        $this->line("Scope: {$scope} | Limit: {$limit}");
        $this->line('');

        if (in_array($scope, ['all', 'service'])) {
            $this->checkS1($limit); // dịch vụ → Cr 3311 (sai)
            $this->checkS2($limit); // items 154/64x → Cr 3311 (sai)
        }

        if (in_array($scope, ['all', 'goods'])) {
            $this->checkS3($limit); // hàng hóa → Cr 3312 (sai)
        }

        $this->line('');
        if ($this->totalIssues === 0) {
            $this->info('✓ Không phát hiện sai TK Có 3311/3312 trên hóa đơn đầu vào.');
        } else {
            $this->error("Tổng cộng: {$this->totalIssues} vấn đề.");
            $this->line('Ghi chú: Nếu kỳ chưa khóa, tạo JE điều chỉnh: Nợ 3311 / Có 3312 để chuyển đúng công nợ.');
            $this->line('Nếu kỳ đã khóa, tạo bút toán đầu kỳ kỳ tiếp.');
        }

        return $this->totalIssues === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * S1: HĐ có invoice_type thuộc nhóm dịch vụ nhưng JE Có 3311.
     * Loại dịch vụ: project_construction, external_service, selling_expense,
     *               management_expense, prepaid_expense.
     */
    private function checkS1(int $limit): void
    {
        $serviceTypes = [
            'project_construction', 'external_service',
            'selling_expense', 'management_expense', 'prepaid_expense',
        ];

        $rows = DB::table('purchase_invoices as pi')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.reference_id', '=', 'pi.id')
                     ->where('je.reference_type', 'purchase_invoice')
                     ->whereIn('je.status', ['draft', 'posted']);
            })
            ->join('journal_entry_lines as jel', function ($join) {
                $join->on('jel.journal_entry_id', '=', 'je.id')
                     ->where('jel.credit', '>', 0)
                     ->where('jel.account_code', 'like', '331%')
                     ->where('jel.account_code', '!=', '331UT');
            })
            ->whereIn('pi.invoice_type', $serviceTypes)
            ->whereNotIn('pi.status', ['cancelled'])
            ->where('jel.account_code', '3311')  // sai: phải là 3312
            ->select([
                'pi.id',
                'pi.code',
                'pi.invoice_date',
                'pi.invoice_type',
                'pi.total',
                'pi.status',
                DB::raw('s.name as supplier_name'),
                DB::raw('jel.account_code as current_cr'),
                DB::raw("'3312' as suggested_cr"),
                DB::raw('je.id as je_id'),
                DB::raw('je.reference_id as pi_id_check'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->totalIssues += $rows->count();
            $this->warn("  S1 ✗ HĐ loại dịch vụ nhưng JE Có 3311 (phải là 3312): {$rows->count()} HĐ");
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày', 'Loại', 'Tổng', 'TK Có hiện tại', 'TK Có đúng', 'Trạng thái', 'NCC', 'JE ID'],
                $rows->map(fn ($r) => [
                    $r->id, $r->code, $r->invoice_date,
                    $r->invoice_type,
                    number_format($r->total),
                    $r->current_cr,
                    $r->suggested_cr,
                    $r->status,
                    mb_strimwidth($r->supplier_name, 0, 20, '…'),
                    $r->je_id,
                ])->all()
            );
            if ($this->option('suggest')) {
                $this->line('  → Lệnh điều chỉnh đề xuất (chạy thủ công sau khi kiểm tra kỳ):');
                foreach ($rows->take(5) as $r) {
                    $this->line("     JE #{$r->je_id} ({$r->code}): Tạo JE Dr 3311 / Cr 3312 = tổng credit 3311 trên JE này.");
                }
            }
            $this->line('');
        } else {
            $this->line('  S1 ✓ Không có HĐ dịch vụ nào bị ghi Có 3311.');
        }
    }

    /**
     * S2: HĐ không có invoice_type nhưng có JE lines Nợ 154/641/642 và Có 3311.
     * Đây là HĐ legacy chưa set invoice_type, phần dịch vụ ghi nhầm TK.
     */
    private function checkS2(int $limit): void
    {
        $rows = DB::table('purchase_invoices as pi')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.reference_id', '=', 'pi.id')
                     ->where('je.reference_type', 'purchase_invoice')
                     ->whereIn('je.status', ['draft', 'posted']);
            })
            ->whereNull('pi.invoice_type')
            ->whereNotIn('pi.status', ['cancelled'])
            // JE có dòng Nợ 154/641/642
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entry_lines as jel2')
                    ->whereColumn('jel2.journal_entry_id', 'je.id')
                    ->where('jel2.debit', '>', 0)
                    ->where(fn ($q) => $q->where('jel2.account_code', 'like', '154%')
                                        ->orWhere('jel2.account_code', 'like', '641%')
                                        ->orWhere('jel2.account_code', 'like', '642%'));
            })
            // JE có dòng Có 3311
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('journal_entry_lines as jel3')
                    ->whereColumn('jel3.journal_entry_id', 'je.id')
                    ->where('jel3.credit', '>', 0)
                    ->where('jel3.account_code', '3311');
            })
            ->select([
                'pi.id',
                'pi.code',
                'pi.invoice_date',
                'pi.total',
                'pi.status',
                DB::raw('s.name as supplier_name'),
                DB::raw('je.id as je_id'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->totalIssues += $rows->count();
            $this->warn("  S2 ✗ HĐ legacy (không có invoice_type) có dòng Nợ 154/64x nhưng Có 3311: {$rows->count()} HĐ");
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày', 'Tổng', 'Trạng thái', 'NCC', 'JE ID'],
                $rows->map(fn ($r) => [
                    $r->id, $r->code, $r->invoice_date,
                    number_format($r->total),
                    $r->status,
                    mb_strimwidth($r->supplier_name, 0, 25, '…'),
                    $r->je_id,
                ])->all()
            );
            $this->line('  → Cần đặt invoice_type rõ ràng cho HĐ và rebuild JE nếu kỳ chưa khóa.');
            $this->line('');
        } else {
            $this->line('  S2 ✓ Không có HĐ legacy dịch vụ nào bị ghi Có 3311.');
        }
    }

    /**
     * S3: HĐ loại hàng hóa (ResaleGoods / RawMaterial / ToolsEquipment) nhưng có JE Có 3312.
     * Trường hợp này ít xảy ra nhưng cần cảnh báo.
     */
    private function checkS3(int $limit): void
    {
        $goodsTypes = ['resale_goods', 'raw_material', 'tools_equipment'];

        $rows = DB::table('purchase_invoices as pi')
            ->join('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->join('journal_entries as je', function ($join) {
                $join->on('je.reference_id', '=', 'pi.id')
                     ->where('je.reference_type', 'purchase_invoice')
                     ->whereIn('je.status', ['draft', 'posted']);
            })
            ->join('journal_entry_lines as jel', function ($join) {
                $join->on('jel.journal_entry_id', '=', 'je.id')
                     ->where('jel.credit', '>', 0)
                     ->where('jel.account_code', '3312');
            })
            ->whereIn('pi.invoice_type', $goodsTypes)
            ->whereNotIn('pi.status', ['cancelled'])
            ->select([
                'pi.id', 'pi.code', 'pi.invoice_date',
                'pi.invoice_type', 'pi.total', 'pi.status',
                DB::raw('s.name as supplier_name'),
                DB::raw('je.id as je_id'),
            ])
            ->orderByDesc('pi.id')
            ->limit($limit)
            ->get();

        if ($rows->isNotEmpty()) {
            $this->totalIssues += $rows->count();
            $this->warn("  S3 ✗ HĐ hàng hóa nhưng JE Có 3312 (bất thường): {$rows->count()} HĐ");
            $this->table(
                ['ID', 'Mã HĐ', 'Ngày', 'Loại', 'Tổng', 'Trạng thái', 'NCC', 'JE ID'],
                $rows->map(fn ($r) => [
                    $r->id, $r->code, $r->invoice_date,
                    $r->invoice_type,
                    number_format($r->total),
                    $r->status,
                    mb_strimwidth($r->supplier_name, 0, 20, '…'),
                    $r->je_id,
                ])->all()
            );
            $this->line('  → Cần kế toán kiểm tra lại loại hóa đơn và bút toán.');
            $this->line('');
        } else {
            $this->line('  S3 ✓ Không có HĐ hàng hóa nào bị ghi Có 3312.');
        }
    }
}
