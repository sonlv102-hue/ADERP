<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
  .page { padding: 40px 50px; }
  .header { text-align: center; margin-bottom: 32px; border-bottom: 2px solid #1f2937; padding-bottom: 20px; }
  .header h1 { font-size: 22px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
  .header .doc-code { font-size: 15px; font-weight: bold; color: #2563eb; }
  .meta-section { margin-bottom: 20px; }
  .meta-section h2 { font-size: 13px; font-weight: bold; text-transform: uppercase; color: #374151; border-left: 3px solid #2563eb; padding-left: 8px; margin-bottom: 10px; }
  .meta-grid { display: flex; gap: 20px; }
  .meta-col { flex: 1; }
  .meta-row { margin-bottom: 6px; font-size: 12px; line-height: 1.5; }
  .meta-row strong { color: #374151; min-width: 130px; display: inline-block; }
  .content-section { margin-bottom: 20px; }
  .clause { margin-bottom: 16px; }
  .clause h3 { font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 6px; }
  .clause p { line-height: 1.7; font-size: 12px; }
  .value-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 14px 20px; text-align: center; margin: 20px 0; }
  .value-box .label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
  .value-box .amount { font-size: 22px; font-weight: bold; color: #2563eb; margin: 6px 0; }
  .value-box .words { font-size: 11px; color: #374151; font-style: italic; }
  .sign-section { margin-top: 40px; }
  .sign-row { display: flex; gap: 30px; }
  .sign-box { flex: 1; text-align: center; }
  .sign-box .sign-title { font-size: 12px; font-weight: bold; text-transform: uppercase; }
  .sign-box .sign-date { font-size: 11px; color: #6b7280; margin-top: 4px; }
  .sign-box .sign-space { height: 60px; }
  .sign-box .sign-name { font-size: 12px; font-weight: bold; border-top: 1px solid #1f2937; padding-top: 6px; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: bold; }
  .badge-gray { background: #f3f4f6; color: #374151; }
  .badge-blue { background: #dbeafe; color: #1d4ed8; }
  .badge-green { background: #dcfce7; color: #166534; }
  .badge-red { background: #fee2e2; color: #991b1b; }
</style>
</head>
@php
$co = \App\Models\Setting::getGroup('company');
$coName = $co['company_name'] ?? 'Mini ERP';
$coAddress = $co['company_address'] ?? '';
$coPhone = $co['company_phone'] ?? '';
$coTax = $co['company_tax_code'] ?? '';
@endphp
<body>
<div class="page">
  <div class="header">
    <h1>Hợp đồng kinh tế</h1>
    <div class="doc-code">Số: {{ $contract->code }}</div>
    @php
      $colors = ['draft'=>'gray','active'=>'green','completed'=>'blue','terminated'=>'red'];
      $sc = $colors[$contract->status->value] ?? 'gray';
    @endphp
    <div style="margin-top:8px">
      <span class="badge badge-{{ $sc }}">{{ $contract->status->label() }}</span>
    </div>
  </div>

  <div class="meta-section">
    <h2>Các bên tham gia</h2>
    <div class="meta-grid">
      <div class="meta-col">
        <div class="meta-row"><strong>Bên A (Người bán):</strong> {{ $coName }}</div>
        <div class="meta-row"><strong>Đại diện:</strong> {{ $contract->creator->name }}</div>
        @if($coAddress)<div class="meta-row"><strong>Địa chỉ:</strong> {{ $coAddress }}</div>@endif
        @if($coTax)<div class="meta-row"><strong>MST:</strong> {{ $coTax }}</div>@endif
      </div>
      <div class="meta-col">
        <div class="meta-row"><strong>Bên B (Khách hàng):</strong> {{ $contract->customer->name }}</div>
        <div class="meta-row"><strong>Mã KH:</strong> {{ $contract->customer->code }}</div>
        @if($contract->customer->phone)
        <div class="meta-row"><strong>Điện thoại:</strong> {{ $contract->customer->phone }}</div>
        @endif
      </div>
    </div>
  </div>

  <div class="meta-section">
    <h2>Thông tin hợp đồng</h2>
    <div class="meta-row"><strong>Tiêu đề:</strong> {{ $contract->title }}</div>
    <div class="meta-grid" style="margin-top:8px">
      <div class="meta-col">
        <div class="meta-row"><strong>Số hợp đồng:</strong> {{ $contract->code }}</div>
        @if($contract->order)
        <div class="meta-row"><strong>Đơn hàng liên kết:</strong> {{ $contract->order->code }}</div>
        @endif
      </div>
      <div class="meta-col">
        @if($contract->start_date)
        <div class="meta-row"><strong>Ngày bắt đầu:</strong> {{ $contract->start_date->format('d/m/Y') }}</div>
        @endif
        @if($contract->end_date)
        <div class="meta-row"><strong>Ngày kết thúc:</strong> {{ $contract->end_date->format('d/m/Y') }}</div>
        @endif
      </div>
    </div>
  </div>

  <div class="value-box">
    <div class="label">Giá trị hợp đồng</div>
    <div class="amount">{{ number_format($contract->value, 0, ',', '.') }} đồng</div>
  </div>

  <div class="content-section">
    <div class="clause">
      <h3>Điều 1. Nội dung hợp đồng</h3>
      <p>Bên A đồng ý cung cấp cho Bên B sản phẩm/dịch vụ theo các điều khoản được mô tả trong hợp đồng này với tổng giá trị nêu trên.</p>
    </div>
    <div class="clause">
      <h3>Điều 2. Phương thức thanh toán</h3>
      <p>Các bên thỏa thuận phương thức thanh toán cụ thể. Bên B có trách nhiệm thanh toán đầy đủ theo đúng thời hạn.</p>
    </div>
    <div class="clause">
      <h3>Điều 3. Điều khoản chung</h3>
      <p>Mọi thay đổi, bổ sung hợp đồng phải được lập thành văn bản và có chữ ký xác nhận của cả hai bên. Hợp đồng này được lập thành 02 bản có giá trị pháp lý ngang nhau, mỗi bên giữ 01 bản.</p>
    </div>
    @if($contract->notes)
    <div class="clause">
      <h3>Ghi chú</h3>
      <p>{{ $contract->notes }}</p>
    </div>
    @endif
  </div>

  <div class="sign-section">
    <div class="sign-row">
      <div class="sign-box">
        <div class="sign-title">Đại diện bên A</div>
        <div class="sign-date">Ngày {{ now()->format('d') }} tháng {{ now()->format('m') }} năm {{ now()->format('Y') }}</div>
        <div class="sign-space"></div>
        <div class="sign-name">{{ $contract->creator->name }}</div>
      </div>
      <div class="sign-box">
        <div class="sign-title">Đại diện bên B</div>
        <div class="sign-date">Ngày {{ now()->format('d') }} tháng {{ now()->format('m') }} năm {{ now()->format('Y') }}</div>
        <div class="sign-space"></div>
        <div class="sign-name">{{ $contract->customer->name }}</div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
