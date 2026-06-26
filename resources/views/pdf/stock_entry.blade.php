<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
  .page { padding: 28px 36px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 2px solid #2563eb; padding-bottom: 14px; }
  .company-name { font-size: 17px; font-weight: bold; color: #2563eb; }
  .company-info { font-size: 10px; color: #6b7280; margin-top: 4px; line-height: 1.5; }
  .doc-title h1 { font-size: 18px; font-weight: bold; text-transform: uppercase; text-align: right; }
  .doc-code { font-size: 14px; font-weight: bold; color: #2563eb; text-align: right; margin-top: 4px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: bold; }
  .badge-gray   { background: #f3f4f6; color: #374151; }
  .badge-blue   { background: #dbeafe; color: #1d4ed8; }
  .badge-green  { background: #dcfce7; color: #166534; }
  .badge-red    { background: #fee2e2; color: #991b1b; }
  .badge-yellow { background: #fef3c7; color: #92400e; }
  .meta-grid { display: flex; gap: 16px; margin-bottom: 18px; }
  .meta-box { flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; }
  .meta-box h3 { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 8px; letter-spacing: 0.05em; }
  .meta-row { font-size: 11px; margin-bottom: 3px; }
  .meta-row strong { color: #374151; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  thead tr { background: #2563eb; color: white; }
  thead th { padding: 7px 10px; text-align: left; font-size: 11px; font-weight: 600; }
  thead th.right { text-align: right; }
  tbody tr { border-bottom: 1px solid #e5e7eb; }
  tbody tr:nth-child(even) { background: #f9fafb; }
  tbody td { padding: 6px 10px; font-size: 11px; }
  tbody td.right { text-align: right; }
  .serial-row td { background: #eff6ff; padding: 4px 10px; font-size: 10px; color: #1e40af; }
  tfoot tr { background: #f3f4f6; border-top: 2px solid #2563eb; }
  tfoot td { padding: 8px 10px; font-size: 12px; font-weight: bold; }
  tfoot td.right { text-align: right; color: #2563eb; }
  .notes-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 10px 14px; margin-bottom: 20px; }
  .notes-box h3 { font-size: 10px; font-weight: bold; color: #92400e; margin-bottom: 4px; }
  .sign-table { width: 100%; margin-top: 28px; border-collapse: collapse; }
  .sign-table td { width: 33.33%; text-align: center; padding: 0 10px; vertical-align: top; }
  .sign-cell-inner { border-top: 1px dashed #d1d5db; padding-top: 8px; }
  .sign-title { font-size: 10px; font-weight: bold; text-transform: uppercase; color: #6b7280; }
  .sign-name { font-size: 11px; color: #1f2937; margin-top: 40px; }
</style>
</head>
@php
$co = \App\Models\Setting::getGroup('company');
$coName    = $co['company_name']    ?? 'Mini ERP';
$coAddress = $co['company_address'] ?? '';
$coPhone   = $co['company_phone']   ?? '';
$coEmail   = $co['company_email']   ?? '';
$coTax     = $co['company_tax_code']?? '';
$coLogoPath = null;
if (!empty($co['company_logo'])) {
    $rel = ltrim(str_replace('/storage', '', $co['company_logo']), '/');
    $abs = storage_path('app/public/' . $rel);
    if (file_exists($abs)) {
        $mime = mime_content_type($abs);
        $coLogoPath = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
    }
}
$colors = ['draft'=>'gray','confirmed'=>'green','cancelled'=>'red'];
$sc = $colors[$stockEntry->status->value] ?? 'gray';
$grandTotal = $stockEntry->items->sum(fn($i) => $i->quantity * $i->unit_price);
@endphp
<body>
<div class="page">

  <div class="header">
    <div style="display:flex;align-items:center;gap:12px">
      @if($coLogoPath)
      <img src="{{ $coLogoPath }}" style="width:44px;height:44px;object-fit:contain;border-radius:6px;" />
      @endif
      <div>
        <div class="company-name">{{ $coName }}</div>
        <div class="company-info">
          @if($coAddress){{ $coAddress }}<br>@endif
          @if($coPhone)ĐT: {{ $coPhone }}@if($coEmail) &nbsp;|&nbsp; @endif
          @endif
          @if($coEmail)Email: {{ $coEmail }}@endif
          @if($coTax)<br>MST: {{ $coTax }}@endif
        </div>
      </div>
    </div>
    <div class="doc-title">
      <h1>Phiếu nhập kho</h1>
      <div class="doc-code">{{ $stockEntry->code }}</div>
      <div style="text-align:right;margin-top:6px">
        <span class="badge badge-{{ $sc }}">{{ $stockEntry->status->label() }}</span>
      </div>
    </div>
  </div>

  <div class="meta-grid">
    <div class="meta-box">
      <h3>Thông tin phiếu</h3>
      <div class="meta-row"><strong>Ngày nhập:</strong> {{ $stockEntry->entry_date->format('d/m/Y') }}</div>
      <div class="meta-row"><strong>Kho nhập:</strong> {{ $stockEntry->warehouse->name }}</div>
      <div class="meta-row"><strong>Người tạo:</strong> {{ $stockEntry->creator->name }}</div>
    </div>
    <div class="meta-box">
      <h3>Nhà cung cấp</h3>
      @if($stockEntry->supplier)
      <div class="meta-row"><strong>Tên NCC:</strong> {{ $stockEntry->supplier->name }}</div>
      @if($stockEntry->supplier->phone)
      <div class="meta-row"><strong>Điện thoại:</strong> {{ $stockEntry->supplier->phone }}</div>
      @endif
      @if($stockEntry->supplier->address)
      <div class="meta-row"><strong>Địa chỉ:</strong> {{ $stockEntry->supplier->address }}</div>
      @endif
      @else
      <div class="meta-row" style="color:#9ca3af">Không có nhà cung cấp</div>
      @endif
    </div>
    <div class="meta-box">
      <h3>Đơn mua hàng</h3>
      <div class="meta-row"><strong>Mã PO:</strong> {{ $stockEntry->purchaseOrder?->code ?? '—' }}</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:30px">#</th>
        <th style="width:80px">Mã SP</th>
        <th>Tên sản phẩm</th>
        <th style="width:50px">ĐVT</th>
        <th class="right" style="width:50px">SL</th>
        <th class="right" style="width:90px">Đơn giá</th>
        <th class="right" style="width:90px">Thành tiền</th>
      </tr>
    </thead>
    <tbody>
      @foreach($stockEntry->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td style="font-family:monospace;font-size:10px">{{ $item->product->code }}</td>
        <td>{{ $item->product->name }}</td>
        <td>{{ $item->product->unit }}</td>
        <td class="right">{{ number_format($item->quantity) }}</td>
        <td class="right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
        <td class="right">{{ number_format($item->quantity * $item->unit_price, 0, ',', '.') }}</td>
      </tr>
      @if($item->serials->count())
      <tr class="serial-row">
        <td colspan="7">
          Serial: {{ $item->serials->pluck('serial_number')->join(', ') }}
        </td>
      </tr>
      @endif
      @endforeach
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6" style="text-align:right">Tổng cộng:</td>
        <td class="right">{{ number_format($grandTotal, 0, ',', '.') }} đ</td>
      </tr>
    </tfoot>
  </table>

  @if($stockEntry->notes)
  <div class="notes-box">
    <h3>Ghi chú</h3>
    <p>{{ $stockEntry->notes }}</p>
  </div>
  @endif

  <table class="sign-table">
    <tr>
      <td>
        <div class="sign-cell-inner">
          <div class="sign-title">Bên giao hàng</div>
          <div class="sign-name">{{ $stockEntry->supplier?->name ?? '' }}</div>
        </div>
      </td>
      <td>
        <div class="sign-cell-inner">
          <div class="sign-title">Thủ kho</div>
          <div class="sign-name"></div>
        </div>
      </td>
      <td>
        <div class="sign-cell-inner">
          <div class="sign-title">Kế toán</div>
          <div class="sign-name">{{ $stockEntry->creator->name }}</div>
        </div>
      </td>
    </tr>
  </table>

</div>
</body>
</html>
