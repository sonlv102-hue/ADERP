<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #1f2937; }
.page { padding: 12mm 10mm; }

.company-header { text-align: center; margin-bottom: 8px; border-bottom: 2px solid #1E3A5F; padding-bottom: 6px; }
.company-name { font-size: 13px; font-weight: bold; text-transform: uppercase; color: #1E3A5F; }
.company-sub { font-size: 9px; color: #6b7280; margin-top: 2px; }
.doc-title { text-align: center; margin-bottom: 4px; }
.doc-title h1 { font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; }
.doc-subtitle { font-size: 10px; color: #374151; margin-top: 2px; }
.meta { font-size: 9px; color: #6b7280; text-align: right; margin-bottom: 6px; }

table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
thead tr { background: #1E3A5F; color: white; }
thead th { padding: 4px 3px; text-align: center; font-size: 8px; font-weight: bold; border: 1px solid #93C5FD; }
thead th.left { text-align: left; }
thead th.right { text-align: right; }
tbody tr { border-bottom: 1px solid #e5e7eb; }
tbody tr:nth-child(even) { background: #f9fafb; }
tbody td { padding: 3px; font-size: 8px; border: 1px solid #e5e7eb; }
tbody td.right { text-align: right; }
tbody td.center { text-align: center; }
tfoot tr { background: #FEF3C7; font-weight: bold; }
tfoot td { padding: 4px 3px; font-size: 8px; border: 1px solid #D97706; }
tfoot td.right { text-align: right; }

.group-header { background: #3B82F6; color: white; font-weight: bold; text-align: center; padding: 3px; font-size: 8px; }

.sign-section { margin-top: 16px; }
.sign-row { display: flex; justify-content: space-around; }
.sign-box { text-align: center; width: 28%; }
.sign-title { font-size: 9px; font-weight: bold; text-transform: uppercase; }
.sign-name { font-size: 9px; margin-top: 36px; color: #374151; }
.sign-date { font-size: 8px; color: #6b7280; margin-bottom: 4px; }

.no-data { text-align: center; color: #9ca3af; padding: 20px; font-style: italic; }

@page { size: A4 landscape; margin: 8mm; }
</style>
</head>

@php
$period  = $payroll->period ?? '';
[$year, $month] = explode('-', $period . '-01');
$companyName    = $company['company_name']     ?? '';
$companyAddress = $company['company_address']  ?? '';
$companyTax     = $company['company_tax_code'] ?? '';
$today          = now()->format('d/m/Y');

$fmt = fn($n) => number_format((float)$n, 0, ',', '.');
@endphp

<body>
<div class="page">

  <!-- Company header -->
  <div class="company-header">
    @if($companyName)
      <div class="company-name">{{ $companyName }}</div>
    @endif
    @if($companyAddress)
      <div class="company-sub">Địa chỉ: {{ $companyAddress }}</div>
    @endif
    @if($companyTax)
      <div class="company-sub">MST: {{ $companyTax }}</div>
    @endif
  </div>

  <!-- Title -->
  <div class="doc-title">
    <h1>Bảng tính - Thanh toán tiền lương</h1>
    <div class="doc-subtitle">Tháng {{ $month }} năm {{ $year }} &nbsp;·&nbsp; {{ $payroll->code }}</div>
  </div>
  <div class="meta">Ngày xuất: {{ $today }}</div>

  <!-- Main table -->
  <table>
    <thead>
      <tr>
        <th rowspan="2" style="width:20px">STT</th>
        <th rowspan="2" style="width:45px" class="left">Mã NV</th>
        <th rowspan="2" style="width:85px" class="left">Họ và tên</th>
        <th rowspan="2" style="width:55px">Bộ phận</th>
        <th rowspan="2" style="width:50px">Chức vụ</th>
        <th colspan="3" class="group-header" style="background:#065F46">Chuyên cần</th>
        <th colspan="4" class="group-header" style="background:#1D4ED8">Thu nhập</th>
        <th colspan="4" class="group-header" style="background:#7C3AED">Khấu trừ</th>
        <th rowspan="2" style="width:60px" class="right">Thực lĩnh</th>
      </tr>
      <tr>
        <th style="width:25px">C.chuẩn</th>
        <th style="width:25px">C.hưởng</th>
        <th style="width:25px">Nghỉ phép</th>
        <th style="width:60px">Lương CB</th>
        <th style="width:60px">Lương theo công</th>
        <th style="width:50px">Phụ cấp</th>
        <th style="width:40px">Điều chỉnh</th>
        <th style="width:45px">BHXH/BHYT/BHTN NV</th>
        <th style="width:40px">TNCN</th>
        <th style="width:40px">Tạm ứng</th>
        <th style="width:50px">Tổng KT</th>
      </tr>
    </thead>
    <tbody>
      @forelse($items as $idx => $item)
      @php
        $totalDeduct = (float)$item['bhxh_employee'] + (float)$item['bhyt_employee']
                     + (float)$item['bhtn_employee'] + (float)$item['pit']
                     + (float)$item['advance'];
        $insTotal = (float)$item['bhxh_employee'] + (float)$item['bhyt_employee'] + (float)$item['bhtn_employee'];
      @endphp
      <tr>
        <td class="center">{{ $idx + 1 }}</td>
        <td>{{ $item['employee_code'] ?? '' }}</td>
        <td>{{ $item['employee_name'] ?? '' }}</td>
        <td class="center">{{ $item['department'] ?? '' }}</td>
        <td class="center">{{ $item['position'] ?? '' }}</td>
        <td class="center">{{ $item['standard_days'] }}</td>
        <td class="center">{{ $item['working_days'] }}</td>
        <td class="center">{{ $item['paid_leave_days'] ?? 0 }}</td>
        <td class="right">{{ $fmt($item['base_salary']) }}</td>
        <td class="right">{{ $fmt($item['gross_salary']) }}</td>
        <td class="right">{{ $fmt((float)$item['allowance'] + (float)$item['allowance_responsibility'] + (float)$item['allowance_lunch'] + (float)$item['allowance_phone'] + (float)$item['allowance_transport'] + (float)$item['allowance_performance']) }}</td>
        <td class="right">{{ $item['adjustment_amount'] != 0 ? (((float)$item['adjustment_amount'] > 0 ? '+' : '') . $fmt($item['adjustment_amount'])) : '' }}</td>
        <td class="right">{{ $fmt($insTotal) }}</td>
        <td class="right">{{ $fmt($item['pit']) }}</td>
        <td class="right">{{ $fmt($item['advance']) }}</td>
        <td class="right">{{ $fmt($totalDeduct) }}</td>
        <td class="right"><strong>{{ $fmt($item['thuc_linh']) }}</strong></td>
      </tr>
      @empty
      <tr><td colspan="17" class="no-data">Không có dữ liệu</td></tr>
      @endforelse
    </tbody>
    <tfoot>
      @php
        $totItems  = collect($items);
        $tDeduct   = $totItems->sum(fn($i) => (float)$i['bhxh_employee'] + (float)$i['bhyt_employee'] + (float)$i['bhtn_employee'] + (float)$i['pit'] + (float)$i['advance']);
        $tInsTotal = $totItems->sum(fn($i) => (float)$i['bhxh_employee'] + (float)$i['bhyt_employee'] + (float)$i['bhtn_employee']);
      @endphp
      <tr>
        <td colspan="8" style="text-align:center">TỔNG CỘNG</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['base_salary'])) }}</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['gross_salary'])) }}</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['allowance'] + (float)$i['allowance_responsibility'] + (float)$i['allowance_lunch'] + (float)$i['allowance_phone'] + (float)$i['allowance_transport'] + (float)$i['allowance_performance'])) }}</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['adjustment_amount'])) }}</td>
        <td class="right">{{ $fmt($tInsTotal) }}</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['pit'])) }}</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['advance'])) }}</td>
        <td class="right">{{ $fmt($tDeduct) }}</td>
        <td class="right">{{ $fmt($totItems->sum(fn($i) => (float)$i['thuc_linh'])) }}</td>
      </tr>
    </tfoot>
  </table>

  <!-- Notes section -->
  @if($payroll->notes)
  <div style="margin-top:8px; font-size:9px; color:#374151;">
    <strong>Ghi chú:</strong> {{ $payroll->notes }}
  </div>
  @endif

  <!-- Signature section -->
  <div class="sign-section">
    <div class="sign-date" style="text-align:right">{{ $companyAddress ? $companyAddress . ', ' : '' }}ngày {{ now()->format('d') }} tháng {{ now()->format('m') }} năm {{ now()->format('Y') }}</div>
    <div class="sign-row">
      <div class="sign-box">
        <div class="sign-title">Người lập bảng</div>
        <div style="font-size:8px;color:#6b7280;">(Ký, ghi rõ họ tên)</div>
        <div class="sign-name">&nbsp;</div>
      </div>
      <div class="sign-box">
        <div class="sign-title">Kế toán trưởng</div>
        <div style="font-size:8px;color:#6b7280;">(Ký, ghi rõ họ tên)</div>
        <div class="sign-name">&nbsp;</div>
      </div>
      <div class="sign-box">
        <div class="sign-title">Giám đốc</div>
        <div style="font-size:8px;color:#6b7280;">(Ký, đóng dấu)</div>
        <div class="sign-name">&nbsp;</div>
      </div>
    </div>
  </div>

</div>
</body>
</html>
