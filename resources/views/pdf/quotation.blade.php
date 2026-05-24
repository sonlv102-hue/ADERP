<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2937; }
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
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  thead tr { background: #2563eb; color: white; }
  thead th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 600; }
  thead th.right { text-align: right; }
  tbody tr { border-bottom: 1px solid #e5e7eb; }
  tbody tr:nth-child(even) { background: #f9fafb; }
  tbody td { padding: 7px 10px; font-size: 11px; }
  tbody td.right { text-align: right; }
  .totals { display: flex; justify-content: flex-end; margin-bottom: 24px; }
  .totals-box { width: 280px; }
  .totals-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #f3f4f6; font-size: 12px; }
  .totals-row.grand { font-weight: bold; font-size: 14px; color: #2563eb; border-top: 2px solid #2563eb; padding-top: 8px; }
  .notes-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px; margin-bottom: 24px; }
  .notes-box h3 { font-size: 11px; font-weight: bold; color: #92400e; margin-bottom: 6px; }
  .sign-table { width: 100%; margin-top: 30px; border-collapse: collapse; }
  .sign-box { width: 50%; text-align: center; border-top: 1px dashed #d1d5db; padding-top: 10px; }
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
          @if($coPhone)ĐT: {{ $coPhone }}@if($coEmail) &nbsp;|&nbsp; @endif @endif
          @if($coEmail)Email: {{ $coEmail }}@endif
          @if($coTax)<br>MST: {{ $coTax }}@endif
        </div>
      </div>
    </div>
    <div class="doc-title">
      <h1>Báo giá</h1>
      <div class="doc-code">{{ $quotation->code }}</div>
      @php
        $colors = ['draft'=>'gray','sent'=>'blue','approved'=>'green','rejected'=>'red','expired'=>'yellow'];
        $sc = $colors[$quotation->status->value] ?? 'gray';
      @endphp
      <div style="margin-top:6px">
        <span class="badge badge-{{ $sc }}">{{ $quotation->status->label() }}</span>
      </div>
    </div>
  </div>

  <div class="meta-grid">
    <div class="meta-box">
      <h3>Thông tin khách hàng</h3>
      <div class="meta-row"><strong>Tên:</strong> <span>{{ $quotation->customer->name }}</span></div>
      <div class="meta-row"><strong>Mã KH:</strong> <span>{{ $quotation->customer->code }}</span></div>
      @if($quotation->customer->phone)
      <div class="meta-row"><strong>Điện thoại:</strong> <span>{{ $quotation->customer->phone }}</span></div>
      @endif
      @if($quotation->customer->email)
      <div class="meta-row"><strong>Email:</strong> <span>{{ $quotation->customer->email }}</span></div>
      @endif
    </div>
    <div class="meta-box">
      <h3>Thông tin báo giá</h3>
      <div class="meta-row"><strong>Ngày tạo:</strong> <span>{{ $quotation->created_at->format('d/m/Y') }}</span></div>
      @if($quotation->valid_until)
      <div class="meta-row"><strong>Hiệu lực đến:</strong> <span>{{ $quotation->valid_until->format('d/m/Y') }}</span></div>
      @endif
      @if($quotation->assignedTo)
      <div class="meta-row"><strong>Người phụ trách:</strong> <span>{{ $quotation->assignedTo->name }}</span></div>
      @endif
      <div class="meta-row"><strong>Người tạo:</strong> <span>{{ $quotation->creator->name }}</span></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:30px">#</th>
        <th>Tên sản phẩm / Dịch vụ</th>
        <th style="width:60px">ĐVT</th>
        <th class="right" style="width:60px">SL</th>
        <th class="right" style="width:110px">Đơn giá</th>
        <th class="right" style="width:60px">CK%</th>
        <th class="right" style="width:120px">Thành tiền</th>
      </tr>
    </thead>
    <tbody>
      @foreach($quotation->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>{{ $item->name }}</td>
        <td>{{ $item->unit ?? '—' }}</td>
        <td class="right">{{ number_format($item->quantity, 0) }}</td>
        <td class="right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
        <td class="right">{{ $item->discount_percent > 0 ? number_format($item->discount_percent, 1).'%' : '—' }}</td>
        <td class="right">{{ number_format($item->lineTotal(), 0, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div class="totals-box">
      <div class="totals-row">
        <span>Tổng trước chiết khấu:</span>
        <span>{{ number_format($quotation->subtotal(), 0, ',', '.') }} đ</span>
      </div>
      @if($quotation->discount_value > 0)
      <div class="totals-row">
        <span>Chiết khấu {{ $quotation->discount_type === 'percent' ? '('.$quotation->discount_value.'%)' : '' }}:</span>
        <span>- {{ number_format($quotation->discountAmount(), 0, ',', '.') }} đ</span>
      </div>
      @endif
      <div class="totals-row grand">
        <span>TỔNG CỘNG:</span>
        <span>{{ number_format($quotation->total(), 0, ',', '.') }} đ</span>
      </div>
    </div>
  </div>

  @if($quotation->notes)
  <div class="notes-box">
    <h3>Ghi chú</h3>
    <p>{{ $quotation->notes }}</p>
  </div>
  @endif

  <table class="sign-table">
    <tr>
      <td class="sign-box">
        <div class="sign-title">Đại diện bên bán</div>
        <div class="sign-name">{{ $quotation->assignedTo?->name ?? $quotation->creator->name }}</div>
      </td>
      <td class="sign-box">
        <div class="sign-title">Đại diện bên mua</div>
        <div class="sign-name">{{ $quotation->customer->name }}</div>
      </td>
    </tr>
  </table>
</div>
</body>
</html>
