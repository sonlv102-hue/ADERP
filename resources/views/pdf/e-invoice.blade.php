<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<style>
  @font-face { font-family: 'DejaVu Sans'; src: url('{{ public_path("fonts/DejaVuSans.ttf") }}') format('truetype'); }
  @font-face { font-family: 'DejaVu Sans'; font-weight: bold; src: url('{{ public_path("fonts/DejaVuSans-Bold.ttf") }}') format('truetype'); }
  * { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; margin: 0; padding: 0; box-sizing: border-box; }
  body { margin: 20px 30px; color: #111; }
  .header { text-align: center; margin-bottom: 8px; }
  .header .title { font-size: 14pt; font-weight: bold; text-transform: uppercase; }
  .header .subtitle { font-size: 11pt; }
  .template-info { text-align: center; font-size: 9pt; color: #555; margin-bottom: 12px; }
  .inv-number { text-align: center; font-size: 11pt; font-weight: bold; margin-bottom: 16px; }
  .parties { display: table; width: 100%; margin-bottom: 12px; }
  .party { display: table-cell; width: 50%; vertical-align: top; padding-right: 10px; }
  .party-label { font-weight: bold; font-size: 9pt; text-transform: uppercase; color: #555; margin-bottom: 4px; }
  .party-name { font-weight: bold; font-size: 11pt; }
  .party-detail { font-size: 9pt; color: #333; line-height: 1.5; }
  table.items { width: 100%; border-collapse: collapse; margin: 12px 0; }
  table.items th { background: #f0f0f0; padding: 5px 6px; text-align: left; font-size: 9pt; border: 1px solid #ccc; }
  table.items td { padding: 5px 6px; border: 1px solid #ddd; font-size: 9pt; }
  table.items td.right { text-align: right; }
  table.items td.center { text-align: center; }
  .totals { margin-top: 8px; float: right; width: 260px; }
  .totals table { width: 100%; border-collapse: collapse; }
  .totals table td { padding: 3px 6px; font-size: 9pt; }
  .totals table td.label { text-align: left; color: #555; }
  .totals table td.amount { text-align: right; font-weight: bold; }
  .totals table .grand td { font-size: 11pt; font-weight: bold; border-top: 2px solid #333; padding-top: 5px; }
  .in-words { margin-top: 10px; font-style: italic; font-size: 9pt; clear: both; padding-top: 8px; }
  .signatures { display: table; width: 100%; margin-top: 32px; }
  .sig-col { display: table-cell; width: 50%; text-align: center; font-size: 9pt; }
  .sig-title { font-weight: bold; margin-bottom: 40px; }
  .status-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9pt; font-weight: bold; }
  .badge-issued { background: #d1fae5; color: #065f46; }
  .badge-cancelled { background: #fee2e2; color: #991b1b; }
  .footer { margin-top: 24px; font-size: 8pt; color: #888; text-align: center; border-top: 1px solid #ddd; padding-top: 6px; }
</style>
</head>
<body>

<!-- Seller header -->
<div class="header">
  <div class="title">{{ $company['company_name'] ?? 'CÔNG TY' }}</div>
  <div style="font-size:9pt; color:#555;">
    {{ $company['company_address'] ?? '' }}
    @if(!empty($company['company_tax_code'])) | MST: {{ $company['company_tax_code'] }} @endif
    @if(!empty($company['company_phone'])) | ĐT: {{ $company['company_phone'] }} @endif
  </div>
</div>

<hr style="border:1px solid #999; margin: 8px 0;">

<!-- Invoice title -->
<div class="header" style="margin-top:10px;">
  <div class="title" style="font-size:13pt;">HÓA ĐƠN GIÁ TRỊ GIA TĂNG</div>
  <div style="font-size:9pt; color:#555; margin-top:2px;">(VAT Invoice / Hóa đơn điện tử)</div>
</div>

<div class="template-info">
  Mẫu số: <strong>{{ $invoice->e_inv_template ?? '01GTKT0/001' }}</strong> &nbsp;|&nbsp;
  Ký hiệu: <strong>{{ $invoice->e_inv_series }}</strong> &nbsp;|&nbsp;
  @if($invoice->e_inv_status === 'cancelled')
    <span class="status-badge badge-cancelled">ĐÃ HỦY</span>
  @else
    Ngày phát hành: <strong>{{ $invoice->e_inv_issued_at?->format('d/m/Y') ?? now()->format('d/m/Y') }}</strong>
  @endif
</div>

<div class="inv-number">
  Số: <strong>{{ str_pad($invoice->e_inv_number, 7, '0', STR_PAD_LEFT) }}</strong>
</div>

<!-- Parties -->
<div class="parties">
  <div class="party">
    <div class="party-label">Đơn vị bán hàng</div>
    <div class="party-name">{{ $company['company_name'] ?? '' }}</div>
    <div class="party-detail">
      MST: {{ $company['company_tax_code'] ?? '' }}<br>
      ĐC: {{ $company['company_address'] ?? '' }}<br>
      TK: {{ $company['company_bank_account'] ?? '' }} - {{ $company['company_bank_name'] ?? '' }}
    </div>
  </div>
  <div class="party">
    <div class="party-label">Người mua hàng</div>
    <div class="party-name">{{ $invoice->customer?->name }}</div>
    <div class="party-detail">
      MST: {{ $invoice->customer?->tax_code ?: '—' }}<br>
      ĐC: {{ $invoice->customer?->address ?: '—' }}<br>
      ĐT: {{ $invoice->customer?->phone ?: '—' }}
    </div>
  </div>
</div>

<!-- Items table -->
@php
  $items = $invoice->order?->items ?? collect();
@endphp

<table class="items">
  <thead>
    <tr>
      <th style="width:4%">STT</th>
      <th>Tên hàng hóa, dịch vụ</th>
      <th style="width:8%; text-align:center">ĐVT</th>
      <th style="width:8%; text-align:center">SL</th>
      <th style="width:14%; text-align:right">Đơn giá</th>
      <th style="width:14%; text-align:right">Thành tiền</th>
    </tr>
  </thead>
  <tbody>
    @if($items->count())
      @foreach($items as $i => $item)
      <tr>
        <td class="center">{{ $i + 1 }}</td>
        <td>{{ $item->name }}</td>
        <td class="center">—</td>
        <td class="center">{{ number_format($item->quantity) }}</td>
        <td class="right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
        <td class="right">{{ number_format($item->subtotal, 0, ',', '.') }}</td>
      </tr>
      @endforeach
    @else
      <tr>
        <td class="center">1</td>
        <td>{{ $invoice->order?->code ? 'Theo đơn hàng ' . $invoice->order->code : 'Hàng hóa / Dịch vụ' }}</td>
        <td class="center">—</td>
        <td class="center">1</td>
        <td class="right">{{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
        <td class="right">{{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
      </tr>
    @endif
  </tbody>
</table>

<!-- Totals -->
<div class="totals">
  <table>
    <tr>
      <td class="label">Cộng tiền hàng:</td>
      <td class="amount">{{ number_format($invoice->subtotal, 0, ',', '.') }} ₫</td>
    </tr>
    @php $vatRate = $invoice->subtotal > 0 ? round($invoice->tax_amount / $invoice->subtotal * 100) : 10; @endphp
    <tr>
      <td class="label">Thuế GTGT ({{ $vatRate }}%):</td>
      <td class="amount">{{ number_format($invoice->tax_amount, 0, ',', '.') }} ₫</td>
    </tr>
    <tr class="grand">
      <td class="label">Tổng thanh toán:</td>
      <td class="amount">{{ number_format($invoice->total, 0, ',', '.') }} ₫</td>
    </tr>
  </table>
</div>

<div class="in-words">
  <strong>Số tiền bằng chữ:</strong>
  <em>{{ \App\Helpers\NumberToWords::toVietnamese((int)$invoice->total) }}</em>
</div>

@if($invoice->e_inv_status === 'cancelled' && $invoice->e_inv_cancel_reason)
<div style="margin-top:12px; padding:8px; background:#fee2e2; border:1px solid #fca5a5; border-radius:4px; font-size:9pt;">
  <strong style="color:#991b1b;">LÝ DO HỦY:</strong> {{ $invoice->e_inv_cancel_reason }}
</div>
@endif

<!-- Signatures -->
<div class="signatures" style="margin-top: 40px;">
  <div class="sig-col">
    <div class="sig-title">NGƯỜI MUA HÀNG<br><span style="font-weight:normal; font-size:8pt;">(Ký, ghi rõ họ tên)</span></div>
    <div>{{ $invoice->customer?->name }}</div>
  </div>
  <div class="sig-col">
    <div class="sig-title">NGƯỜI BÁN HÀNG<br><span style="font-weight:normal; font-size:8pt;">(Ký, đóng dấu, ghi rõ họ tên)</span></div>
    <div>{{ $company['company_name'] ?? '' }}</div>
  </div>
</div>

<div class="footer">
  Hóa đơn điện tử — Phát hành {{ $invoice->e_inv_issued_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }} |
  Mẫu: {{ $invoice->e_inv_template ?? '01GTKT0/001' }} | Ký hiệu: {{ $invoice->e_inv_series }} | Số: {{ str_pad($invoice->e_inv_number, 7, '0', STR_PAD_LEFT) }}
</div>

</body>
</html>
