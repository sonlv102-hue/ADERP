<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
  .page { padding: 24px 30px; }
  .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 2px solid #2563eb; padding-bottom: 12px; }
  .company-name { font-size: 15px; font-weight: bold; color: #2563eb; }
  .company-info { font-size: 10px; color: #6b7280; margin-top: 3px; }
  .doc-title h1 { font-size: 16px; font-weight: bold; text-transform: uppercase; text-align: right; }
  .doc-meta { font-size: 10px; color: #6b7280; text-align: right; margin-top: 3px; }
  table { width: 100%; border-collapse: collapse; }
  thead tr { background: #2563eb; color: white; }
  thead th { padding: 7px 8px; text-align: left; font-size: 10px; font-weight: 600; }
  thead th.right { text-align: right; }
  tbody tr { border-bottom: 1px solid #e5e7eb; }
  tbody tr:nth-child(even) { background: #f9fafb; }
  tbody td { padding: 6px 8px; font-size: 10px; }
  tbody td.right { text-align: right; }
  .badge { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: bold; }
  .badge-gray   { background: #f3f4f6; color: #374151; }
  .badge-green  { background: #dcfce7; color: #166534; }
  .badge-red    { background: #fee2e2; color: #991b1b; }
  .badge-blue   { background: #dbeafe; color: #1d4ed8; }
  .footer { margin-top: 16px; font-size: 10px; color: #9ca3af; text-align: right; }
</style>
</head>
@php
$co = \App\Models\Setting::getGroup('company');
$coName    = $co['company_name']    ?? 'Mini ERP';
$coAddress = $co['company_address'] ?? '';
$coPhone   = $co['company_phone']   ?? '';
$colors = ['draft'=>'gray','confirmed'=>'green','cancelled'=>'red'];
@endphp
<body>
<div class="page">

  <div class="header">
    <div>
      <div class="company-name">{{ $coName }}</div>
      <div class="company-info">
        @if($coAddress){{ $coAddress }}@endif
        @if($coPhone) — ĐT: {{ $coPhone }}@endif
      </div>
    </div>
    <div>
      <div class="doc-title"><h1>Danh sách phiếu nhập kho</h1></div>
      <div class="doc-meta">Xuất ngày: {{ now()->format('d/m/Y H:i') }} — Tổng: {{ $entries->count() }} phiếu</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:30px">#</th>
        <th style="width:85px">Mã phiếu</th>
        <th style="width:70px">Ngày nhập</th>
        <th>Kho nhập</th>
        <th>Nhà cung cấp</th>
        <th class="right" style="width:40px">Dòng</th>
        <th style="width:90px">Trạng thái</th>
        <th style="width:90px">Người tạo</th>
      </tr>
    </thead>
    <tbody>
      @foreach($entries as $i => $entry)
      @php $sc = $colors[$entry->status->value] ?? 'gray'; @endphp
      <tr>
        <td>{{ $i + 1 }}</td>
        <td style="font-family:monospace;font-size:9px">{{ $entry->code }}</td>
        <td>{{ $entry->entry_date->format('d/m/Y') }}</td>
        <td>{{ $entry->warehouse->name }}</td>
        <td>{{ $entry->supplier?->name ?? '—' }}</td>
        <td class="right">{{ $entry->items_count }}</td>
        <td><span class="badge badge-{{ $sc }}">{{ $entry->status->label() }}</span></td>
        <td>{{ $entry->creator->name }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="footer">In từ hệ thống Mini ERP</div>
</div>
</body>
</html>
