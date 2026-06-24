<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #111; }
.page { padding: 10mm 10mm 8mm 12mm; }

.header-wrap { display: flex; justify-content: space-between; margin-bottom: 6px; }
.company-block { font-size: 8.5px; line-height: 1.5; }
.company-name  { font-weight: bold; font-size: 10px; }

.title-block { text-align: center; margin: 6px 0 3px; }
.title-main  { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
.title-sub   { font-size: 8.5px; font-style: italic; margin-top: 2px; }

table { width: 100%; border-collapse: collapse; margin-top: 4px; }

thead tr.group-row th {
    background: #1E3A5F; color: #fff;
    padding: 3px 3px; text-align: center;
    font-size: 8.5px; font-weight: bold;
    border: 0.5pt solid #93c5fd;
}
thead tr.col-row th {
    background: #1E3A5F; color: #fff;
    padding: 3px 3px; text-align: center;
    font-size: 8px; border: 0.5pt solid #93c5fd;
}

tbody td { padding: 2px 3px; border: 0.5pt solid #d1d5db; font-size: 8px; vertical-align: middle; }
.td-center  { text-align: center; }
.td-right   { text-align: right; white-space: nowrap; }
.td-mono    { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5px; }

tr.total-row td { background: #EFF6FF; font-weight: bold; border: 0.5pt solid #93c5fd; }

.warn { background: #FFF7ED; color: #C2410C; font-weight: bold;
        padding: 4px 8px; border-radius: 3px; margin-bottom: 5px;
        font-size: 8.5px; border: 0.5pt solid #FCA974; }

.sign-section { margin-top: 12px; }
.sign-date    { text-align: right; font-size: 8px; font-style: italic; margin-bottom: 8px; }
.sign-row     { display: flex; justify-content: space-between; }
.sign-box     { text-align: center; width: 30%; }
.sign-title   { font-size: 8.5px; font-weight: bold; text-transform: uppercase; }
.sign-note    { font-size: 7.5px; font-style: italic; color: #555; }
.sign-name    { font-size: 8px; margin-top: 28px; }

@page { size: A4 landscape; margin: 0; }
</style>
</head>
<body>
<div class="page">

  {{-- Company header --}}
  <div class="header-wrap">
    <div class="company-block">
      <div class="company-name">{{ $company['name'] ?? '' }}</div>
      <div>{{ $company['address'] ?? '' }}</div>
    </div>
  </div>

  {{-- Title --}}
  <div class="title-block">
    <div class="title-main">Bảng kê chứng từ</div>
    <div class="title-sub">
      Từ ngày: {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
      &nbsp;&nbsp;—&nbsp;&nbsp;
      Đến ngày: {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
    </div>
  </div>

  {{-- Balance warning --}}
  @if(!$isBalanced)
  <div class="warn">
    ⚠ Cảnh báo: Tổng phát sinh Nợ ({{ number_format($totals['debit'], 0, ',', '.') }})
    ≠ Tổng phát sinh Có ({{ number_format($totals['credit'], 0, ',', '.') }}) — kiểm tra lại bút toán.
  </div>
  @endif

  {{-- Table --}}
  <table>
    <thead>
      <tr class="group-row">
        <th colspan="2">CHỨNG TỪ</th>
        <th rowspan="2" style="width:18%">TÊN KHÁCH</th>
        <th rowspan="2" style="width:24%">DIỄN GIẢI</th>
        <th rowspan="2" style="width:8%">TÀI KHOẢN</th>
        <th rowspan="2" style="width:8%">TK ĐỐI ỨNG</th>
        <th colspan="2">SỐ PHÁT SINH</th>
      </tr>
      <tr class="col-row">
        <th style="width:8%">NGÀY</th>
        <th style="width:10%">SỐ</th>
        <th style="width:12%">NỢ</th>
        <th style="width:12%">CÓ</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $row)
      <tr>
        <td class="td-center">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
        <td class="td-center td-mono">{{ $row['je_code'] }}</td>
        <td>{{ $row['object_name'] }}</td>
        <td>{{ $row['description'] }}</td>
        <td class="td-center td-mono">{{ $row['account_code'] }}</td>
        <td class="td-center td-mono">{{ $row['counter_account'] }}</td>
        <td class="td-right">{{ $row['debit']  > 0 ? number_format($row['debit'],  0, ',', '.') : '' }}</td>
        <td class="td-right">{{ $row['credit'] > 0 ? number_format($row['credit'], 0, ',', '.') : '' }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="8" style="text-align:center;padding:10px;color:#6b7280">Không có dữ liệu</td>
      </tr>
      @endforelse
      <tr class="total-row">
        <td colspan="6" style="text-align:center">TỔNG CỘNG</td>
        <td class="td-right">{{ number_format($totals['debit'],  0, ',', '.') }}</td>
        <td class="td-right">{{ number_format($totals['credit'], 0, ',', '.') }}</td>
      </tr>
    </tbody>
  </table>

  {{-- Signature --}}
  <div class="sign-section">
    <div class="sign-date">Ngày {{ now()->format('d') }} tháng {{ now()->format('m') }} năm {{ now()->format('Y') }}</div>
    <div class="sign-row">
      <div class="sign-box">
        <div class="sign-title">Người lập biểu</div>
        <div class="sign-note">(Ký, họ tên)</div>
        <div class="sign-name"></div>
      </div>
      <div class="sign-box">
        <div class="sign-title">Kế toán trưởng</div>
        <div class="sign-note">(Ký, họ tên)</div>
        <div class="sign-name"></div>
      </div>
      <div class="sign-box">
        <div class="sign-title">Giám đốc</div>
        <div class="sign-note">(Ký, họ tên, đóng dấu)</div>
        <div class="sign-name"></div>
      </div>
    </div>
  </div>

</div>
</body>
</html>
