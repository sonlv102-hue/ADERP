<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function index(Request $request): Response
    {
        $period = $request->input('period', now()->format('Y-m'));
        
        try {
            $carbon = Carbon::createFromFormat('Y-m', $period);
        } catch (\Exception $e) {
            $period = now()->format('Y-m');
            $carbon = Carbon::createFromFormat('Y-m', $period);
        }
        
        $start = $carbon->copy()->startOfMonth()->toDateString();
        $end = $carbon->copy()->endOfMonth()->toDateString();

        // Output VAT (Sales Invoices)
        $salesInvoices = Invoice::with('customer')
            ->where('status', '!=', 'draft')
            ->whereBetween('issue_date', [$start, $end])
            ->orderBy('issue_date')
            ->get()
            ->map(fn (Invoice $inv) => [
                'id'            => $inv->id,
                'code'          => $inv->code,
                'issue_date'    => $inv->issue_date->format('d/m/Y'),
                'customer_name' => $inv->customer?->name,
                'tax_code'      => $inv->customer?->tax_code ?? 'Không có',
                'subtotal'      => (float) $inv->subtotal,
                'tax_amount'    => (float) $inv->tax_amount,
                'total'         => (float) $inv->total,
            ]);

        // Input VAT (Purchase Invoices)
        $purchaseInvoices = PurchaseInvoice::with('supplier')
            ->where('status', '!=', 'draft')
            ->whereBetween('invoice_date', [$start, $end])
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (PurchaseInvoice $inv) => [
                'id'             => $inv->id,
                'code'           => $inv->code,
                'invoice_number' => $inv->invoice_number ?? $inv->code,
                'invoice_date'   => $inv->invoice_date ? $inv->invoice_date->format('d/m/Y') : null,
                'supplier_name'  => $inv->supplier?->name,
                'tax_code'       => $inv->supplier_tax_code ?? $inv->supplier?->tax_code ?? 'Không có',
                'subtotal'       => (float) $inv->subtotal,
                'tax_amount'     => (float) $inv->tax_amount,
                'total'          => (float) $inv->total,
            ]);

        $totalSalesSubtotal = $salesInvoices->sum('subtotal');
        $totalSalesTax = $salesInvoices->sum('tax_amount');
        $totalPurchaseSubtotal = $purchaseInvoices->sum('subtotal');
        $totalPurchaseTax = $purchaseInvoices->sum('tax_amount');

        $netTaxPayable = $totalSalesTax - $totalPurchaseTax;

        return Inertia::render('Accounting/Taxes/Index', [
            'period'           => $period,
            'salesInvoices'    => $salesInvoices,
            'purchaseInvoices' => $purchaseInvoices,
            'summary'          => [
                'total_sales_subtotal'    => (float) $totalSalesSubtotal,
                'total_sales_tax'         => (float) $totalSalesTax,
                'total_purchase_subtotal' => (float) $totalPurchaseSubtotal,
                'total_purchase_tax'      => (float) $totalPurchaseTax,
                'net_tax_payable'         => (float) $netTaxPayable,
            ]
        ]);
    }

    public function exportXml(Request $request)
    {
        $period = $request->input('period', now()->format('Y-m'));
        try {
            $carbon = Carbon::createFromFormat('Y-m', $period);
        } catch (\Exception $e) {
            $period = now()->format('Y-m');
            $carbon = Carbon::createFromFormat('Y-m', $period);
        }
        
        $start = $carbon->copy()->startOfMonth()->toDateString();
        $end = $carbon->copy()->endOfMonth()->toDateString();

        $sales = Invoice::where('status', '!=', 'draft')
            ->whereBetween('issue_date', [$start, $end])
            ->get();

        $purchases = PurchaseInvoice::where('status', '!=', 'draft')
            ->whereBetween('invoice_date', [$start, $end])
            ->get();

        $totalSalesSubtotal = $sales->sum('subtotal');
        $totalSalesTax = $sales->sum('tax_amount');
        $totalPurchaseSubtotal = $purchases->sum('subtotal');
        $totalPurchaseTax = $purchases->sum('tax_amount');

        $netTaxPayable = $totalSalesTax - $totalPurchaseTax;
        
        $taxPayable = max(0, $netTaxPayable);
        $taxDeductible = $netTaxPayable < 0 ? abs($netTaxPayable) : 0;

        $companyName = Setting::get('company_name', 'CÔNG TY TNHH MINI ERP VIỆT NAM');
        $companyTaxCode = Setting::get('company_tax_code', '0102030405');
        $companyAddress = Setting::get('company_address', 'Số 1, đường số 4A, Hà Nội');
        $companyPhone = Setting::get('company_phone', '0243.123456');

        $month = $carbon->month;
        $year = $carbon->year;

        // Build XML in HTKK format
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<HSoThueDTu xmlns="http://kekhaithue.gdt.gov.vn/TKhaiDienTu" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= '  <TTinChung>' . "\n";
        $xml .= '    <MaTKhai>01</MaTKhai>' . "\n";
        $xml .= '    <TenTKhai>Tờ khai thuế giá trị gia tăng</TenTKhai>' . "\n";
        $xml .= "    <KyKKhaiThue>M</KyKKhaiThue>\n";
        $xml .= "    <KyKKhaiThueTitle>Tháng {$month} năm {$year}</KyKKhaiThueTitle>\n";
        $xml .= "    <NguoiNopThue>{$companyName}</NguoiNopThue>\n";
        $xml .= "    <MaSoThue>{$companyTaxCode}</MaSoThue>\n";
        $xml .= "    <DiaChi>{$companyAddress}</DiaChi>\n";
        $xml .= "    <DienThoai>{$companyPhone}</DienThoai>\n";
        $xml .= "    <CoQuanThueQuanLy>Cục Thuế Thành Phố</CoQuanThueQuanLy>\n";
        $xml .= '  </TTinChung>' . "\n";
        $xml .= '  <CTietTKhaiDTu>' . "\n";
        $xml .= '    <toKhai01GTGT>' . "\n";
        $xml .= '      <ct21>0</ct21>' . "\n";
        $xml .= '      <ct22>0</ct22>' . "\n";
        $xml .= "      <ct23>{$totalPurchaseSubtotal}</ct23>\n";
        $xml .= "      <ct24>{$totalPurchaseTax}</ct24>\n";
        $xml .= "      <ct25>{$totalPurchaseTax}</ct25>\n";
        $xml .= '      <ct26>0</ct26>' . "\n";
        $xml .= '      <ct27>0</ct27>' . "\n";
        $xml .= '      <ct28>0</ct28>' . "\n";
        $xml .= '      <ct29>0</ct29>' . "\n";
        $xml .= '      <ct30>0</ct30>' . "\n";
        $xml .= '      <ct31>0</ct31>' . "\n";
        $xml .= "      <ct32>{$totalSalesSubtotal}</ct32>\n";
        $xml .= "      <ct33>{$totalSalesTax}</ct33>\n";
        $xml .= "      <ct34>{$totalSalesSubtotal}</ct34>\n";
        $xml .= "      <ct35>{$totalSalesTax}</ct35>\n";
        $xml .= "      <ct36>{$netTaxPayable}</ct36>\n";
        $xml .= "      <ct40>{$taxPayable}</ct40>\n";
        $xml .= "      <ct43>{$taxDeductible}</ct43>\n";
        $xml .= '    </toKhai01GTGT>' . "\n";
        $xml .= '  </CTietTKhaiDTu>' . "\n";
        $xml .= '</HSoThueDTu>' . "\n";

        $fileName = "tokhai_01GTGT_{$period}_" . time() . ".xml";

        return response($xml, 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
