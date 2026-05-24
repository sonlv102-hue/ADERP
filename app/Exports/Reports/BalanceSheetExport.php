<?php

namespace App\Exports\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BalanceSheetExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private array $filters = []) {}

    public function title(): string { return 'Cân đối kế toán'; }

    public function collection(): Collection
    {
        $asOf = $this->filters['as_of'] ?? now()->toDateString();

        $cashIn  = (float) DB::table('payments')->where('payment_date', '<=', $asOf)->sum('amount');
        $cashOut = (float) DB::table('purchase_invoice_payments')->where('payment_date', '<=', $asOf)->sum('amount');
        $cash    = $cashIn - $cashOut;

        $ar = max(0, (float) DB::table('invoices')
            ->whereNotIn('status', ['cancelled'])->where('issue_date', '<=', $asOf)->sum('total') - $cashIn);

        $stockIn  = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'in')->where('stock_movements.created_at', '<=', $asOf . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $stockOut = (float) DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('type', 'out')->where('stock_movements.created_at', '<=', $asOf . ' 23:59:59')
            ->sum(DB::raw('stock_movements.quantity * COALESCE(products.cost_price, 0)'));
        $inventory = max(0, $stockIn - $stockOut);

        $faGross  = (float) DB::table('fixed_assets')->whereNull('deleted_at')->where('acquisition_date', '<=', $asOf)->sum('acquisition_cost');
        $faAccDep = (float) DB::table('fixed_assets')->whereNull('deleted_at')->where('acquisition_date', '<=', $asOf)->sum('accumulated_depreciation');
        $faNet    = max(0, $faGross - $faAccDep);

        $ap = (float) DB::table('purchase_invoices')
            ->whereNotIn('status', ['cancelled'])->where('invoice_date', '<=', $asOf)
            ->sum(DB::raw('GREATEST(0, total - paid_amount)'));

        $vatOut = (float) DB::table('invoices')->whereNotIn('status', ['draft', 'cancelled'])->where('issue_date', '<=', $asOf)->sum('tax_amount');
        $vatIn  = (float) DB::table('purchase_invoices')->whereNotNull('invoice_date')->where('invoice_date', '<=', $asOf)->sum('tax_amount');
        $vatPayable = max(0, $vatOut - $vatIn);

        $revenue = (float) DB::table('invoices')->whereNotIn('status', ['draft', 'cancelled'])->where('issue_date', '<=', $asOf)->sum('subtotal');
        $cogs    = (float) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.order_date', '<=', $asOf)->whereNotIn('orders.status', ['draft', 'cancelled'])
            ->sum(DB::raw('order_items.quantity * COALESCE(products.cost_price, 0)'));
        $retained = $revenue - $cogs;

        $totalAssets  = $cash + $ar + $inventory + $faNet;
        $totalLiabEq  = $ap + $vatPayable + $retained;

        return collect([
            (object)['label' => '=== TÀI SẢN ===',                  'amount' => null],
            (object)['label' => 'A. Tài sản ngắn hạn',               'amount' => $cash + $ar + $inventory],
            (object)['label' => '  I. Tiền gửi ngân hàng (TK 112)', 'amount' => $cash],
            (object)['label' => '  II. Phải thu KH (TK 131)',        'amount' => $ar],
            (object)['label' => '  III. Hàng tồn kho (TK 156)',     'amount' => $inventory],
            (object)['label' => 'B. Tài sản dài hạn',                'amount' => $faNet],
            (object)['label' => '  I. TSCĐ - Nguyên giá (TK 211)',  'amount' => $faGross],
            (object)['label' => '     Hao mòn (TK 214)',             'amount' => -$faAccDep],
            (object)['label' => '     Giá trị còn lại',              'amount' => $faNet],
            (object)['label' => 'TỔNG CỘNG TÀI SẢN',                'amount' => $totalAssets],
            (object)['label' => '',                                   'amount' => null],
            (object)['label' => '=== NGUỒN VỐN ===',                 'amount' => null],
            (object)['label' => 'A. Nợ phải trả',                    'amount' => $ap + $vatPayable],
            (object)['label' => '  I. Phải trả NCC (TK 331)',        'amount' => $ap],
            (object)['label' => '  II. Thuế GTGT phải nộp (3331)',   'amount' => $vatPayable],
            (object)['label' => 'B. Vốn chủ sở hữu',                'amount' => $retained],
            (object)['label' => '  Lợi nhuận chưa phân phối',       'amount' => $retained],
            (object)['label' => 'TỔNG CỘNG NGUỒN VỐN',              'amount' => $totalLiabEq],
        ]);
    }

    public function headings(): array { return ['Chỉ tiêu', 'Số tiền (VND)']; }
    public function map($row): array  { return [$row->label, $row->amount]; }
    public function styles(Worksheet $sheet): array { return [1 => ['font' => ['bold' => true]]]; }
}
