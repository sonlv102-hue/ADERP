<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
  .page { padding: 30px 36px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; border-bottom: 2px solid #2563eb; padding-bottom: 16px; }
  .company-name { font-size: 18px; font-weight: bold; color: #2563eb; }
  .company-info { font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.5; }
  .doc-title { text-align: right; }
  .doc-title h1 { font-size: 20px; font-weight: bold; color: #1f2937; text-transform: uppercase; }
  .doc-code { font-size: 14px; font-weight: bold; color: #2563eb; margin-top: 4px; }
  .meta-grid { display: flex; gap: 24px; margin-bottom: 20px; }
  .meta-box { flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; }
  .meta-box h3 { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 8px; letter-spacing: 0.05em; }
  .meta-row { font-size: 12px; margin-bottom: 4px; }
  .meta-row strong { color: #374151; }
  .meta-row span { color: #1f2937; }
  .totals { display: flex; justify-content: flex-end; margin-bottom: 24px; }
  .totals-box { width: 300px; }
  .totals-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
  .totals-row.grand { font-weight: bold; font-size: 14px; color: #2563eb; border-top: 2px solid #2563eb; padding-top: 8px; }
  .totals-row.paid { color: #166534; }
  .totals-row.due { color: #dc2626; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  thead tr { background: #2563eb; color: white; }
  thead th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
  thead th.right { text-align: right; }
  tbody tr { border-bottom: 1px solid #e5e7eb; }
  tbody tr:nth-child(even) { background: #f9fafb; }
  tbody td { padding: 7px 10px; font-size: 11px; }
  tbody td.right { text-align: right; }
  .notes-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px; margin-bottom: 24px; }
  .notes-box h3 { font-size: 11px; font-weight: bold; color: #92400e; margin-bottom: 6px; }
  .sign-row { display: flex; gap: 24px; margin-top: 30px; }
  .sign-box { flex: 1; text-align: center; border-top: 1px dashed #d1d5db; padding-top: 10px; }
  .sign-box .sign-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; }
  .sign-box .sign-name { font-size: 12px; color: #1f2937; margin-top: 40px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; }
  .badge-gray { background: #f3f4f6; color: #374151; }
  .badge-blue { background: #dbeafe; color: #1d4ed8; }
  .badge-green { background: #dcfce7; color: #166534; }
  .badge-red { background: #fee2e2; color: #991b1b; }
  .badge-yellow { background: #fef3c7; color: #92400e; }
</style>
</head>
@php
$co = \App\Models\Setting::getGroup('company');
$coName = $co['company_name'] ?? 'Mini ERP';
$coAddress = $co['company_address'] ?? '';
$coPhone = $co['company_phone'] ?? '';
$coEmail = $co['company_email'] ?? '';
$coTax = $co['company_tax_code'] ?? '';
$coBankName = $co['company_bank_name'] ?? '';
$coBankAccount = $co['company_bank_account'] ?? '';
$coBankBranch = $co['company_bank_branch'] ?? '';
$coLogoPath = null;
if (!empty($co['company_logo'])) {
    $rel = ltrim(str_replace('/storage', '', $co['company_logo']), '/');
    $abs = storage_path('app/public/' . $rel);
    if (file_exists($abs)) {
        $mime = mime_content_type($abs);
        $coLogoPath = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
    }
}
@endphp
<body>
<div class="page">
  <div class="header">
    <div style="display:flex;align-items:center;gap:12px">
      @if($coLogoPath)
      <img src="{{ $coLogoPath }}" style="width:48px;height:48px;object-fit:contain;border-radius:6px;" />
      @endif
      <div>
        <div class="company-name">{{ $coName }}</div>
        <div class="company-info">
          @if($coAddress){{ $coAddress }}<br>@endif
          @if($coPhone)ĐT: {{ $coPhone }}@if($coEmail) &nbsp;|&nbsp; @endif@endif
          @if($coEmail)Email: {{ $coEmail }}@endif
          @if($coTax)<br>MST: {{ $coTax }}@endif
          @if($coBankName)<br>Ngân hàng: {{ $coBankName }}@if($coBankAccount) — TK: {{ $coBankAccount }}@endif@if($coBankBranch) — {{ $coBankBranch }}@endif@endif
        </div>
      </div>
    </div>
    <div class="doc-title">
      <h1>Hóa đơn</h1>
      <div class="doc-code">{{ $invoice->code }}</div>
      @php
        $colors = ['draft'=>'gray','sent'=>'blue','paid'=>'green','overdue'=>'red'];
        $sc = $colors[$invoice->status->value] ?? 'gray';
      @endphp
      <div style="margin-top:6px">
        <span class="badge badge-{{ $sc }}">{{ $invoice->status->label() }}</span>
      </div>
    </div>
  </div>

  <div class="meta-grid">
    <div class="meta-box">
      <h3>Thông tin khách hàng</h3>
      <div class="meta-row"><strong>Tên:</strong> <span>{{ $invoice->customer->name }}</span></div>
      <div class="meta-row"><strong>Mã KH:</strong> <span>{{ $invoice->customer->code }}</span></div>
      @if($invoice->customer->phone)
      <div class="meta-row"><strong>Điện thoại:</strong> <span>{{ $invoice->customer->phone }}</span></div>
      @endif
      @if($invoice->customer->email)
      <div class="meta-row"><strong>Email:</strong> <span>{{ $invoice->customer->email }}</span></div>
      @endif
    </div>
    <div class="meta-box">
      <h3>Thông tin hóa đơn</h3>
      <div class="meta-row"><strong>Ngày phát hành:</strong> <span>{{ $invoice->issue_date->format('d/m/Y') }}</span></div>
      @if($invoice->due_date)
      <div class="meta-row"><strong>Hạn thanh toán:</strong> <span>{{ $invoice->due_date->format('d/m/Y') }}</span></div>
      @endif
      @if($invoice->order)
      <div class="meta-row"><strong>Đơn hàng:</strong> <span>{{ $invoice->order->code }}</span></div>
      @endif
      @if($invoice->contract)
      <div class="meta-row"><strong>Hợp đồng:</strong> <span>{{ $invoice->contract->code }}</span></div>
      @endif
      <div class="meta-row"><strong>Người tạo:</strong> <span>{{ $invoice->creator->name }}</span></div>
    </div>
  </div>

  @php $invItems = $invoice->items->sortBy('sort_order'); @endphp
  @if($invItems->count())
  <h3 style="font-size:13px; font-weight:bold; margin-bottom:10px; color:#374151;">Chi tiết hàng hóa / dịch vụ</h3>
  <table>
    <thead>
      <tr>
        <th style="width:4%">#</th>
        <th>Diễn giải</th>
        <th class="right" style="width:8%">SL</th>
        <th class="right" style="width:14%">Đơn giá</th>
        <th class="right" style="width:8%">Thuế suất</th>
        <th class="right" style="width:12%">Tiền thuế</th>
        <th class="right" style="width:14%">Thành tiền</th>
      </tr>
    </thead>
    <tbody>
      @foreach($invItems as $idx => $item)
      <tr>
        <td>{{ $idx + 1 }}</td>
        <td>{{ $item->description }}</td>
        <td class="right">{{ rtrim(rtrim(number_format((float)$item->quantity, 3, ',', '.'), '0'), ',') }}</td>
        <td class="right">{{ number_format((float)$item->unit_price, 0, ',', '.') }} đ</td>
        <td class="right">{{ (int)$item->vat_rate }}%</td>
        <td class="right">{{ number_format($item->tax_amount, 0, ',', '.') }} đ</td>
        <td class="right">{{ number_format((float)$item->quantity * (float)$item->unit_price + $item->tax_amount, 0, ',', '.') }} đ</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <div class="totals">
    <div class="totals-box">
      <div class="totals-row">
        <span>Tổng trước thuế:</span>
        <span>{{ number_format($invoice->subtotal, 0, ',', '.') }} đ</span>
      </div>
      <div class="totals-row">
        <span>Thuế (VAT):</span>
        <span>{{ number_format($invoice->tax_amount, 0, ',', '.') }} đ</span>
      </div>
      <div class="totals-row grand">
        <span>TỔNG CỘNG:</span>
        <span>{{ number_format($invoice->total, 0, ',', '.') }} đ</span>
      </div>
      @php $paid = $invoice->payments->sum('amount'); $due = $invoice->total - $paid; @endphp
      @if($paid > 0)
      <div class="totals-row paid">
        <span>Đã thanh toán:</span>
        <span>{{ number_format($paid, 0, ',', '.') }} đ</span>
      </div>
      @endif
      @if($due > 0)
      <div class="totals-row due">
        <span>Còn lại:</span>
        <span>{{ number_format($due, 0, ',', '.') }} đ</span>
      </div>
      @endif
    </div>
  </div>

  @if($invoice->payments->count() > 0)
  <h3 style="font-size:13px; font-weight:bold; margin-bottom:10px; color:#374151;">Lịch sử thanh toán</h3>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Ngày</th>
        <th>Phương thức</th>
        <th>Mã tham chiếu</th>
        <th class="right">Số tiền</th>
      </tr>
    </thead>
    <tbody>
      @foreach($invoice->payments as $i => $pay)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $pay->payment_date->format('d/m/Y') }}</td>
        <td>{{ $pay->method->label() }}</td>
        <td>{{ $pay->reference ?? '—' }}</td>
        <td class="right">{{ number_format($pay->amount, 0, ',', '.') }} đ</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  @if($invoice->notes)
  <div class="notes-box">
    <h3>Ghi chú</h3>
    <p>{{ $invoice->notes }}</p>
  </div>
  @endif

  <div class="sign-row">
    <div class="sign-box">
      <div class="sign-title">Kế toán</div>
      <div class="sign-name">{{ $invoice->creator->name }}</div>
    </div>
    <div class="sign-box">
      <div class="sign-title">Đại diện khách hàng</div>
      <div class="sign-name">{{ $invoice->customer->name }}</div>
    </div>
  </div>
</div>
</body>
</html>
