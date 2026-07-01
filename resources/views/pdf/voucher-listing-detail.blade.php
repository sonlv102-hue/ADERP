<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #111; }
.page { padding: 10mm 8mm 8mm 10mm; }

.header-wrap { display: flex; justify-content: space-between; margin-bottom: 6px; }
.company-block { font-size: 8px; line-height: 1.5; }
.company-name  { font-weight: bold; font-size: 10px; }

.title-block { text-align: center; margin: 6px 0 3px; }
.title-main  { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
.title-sub   { font-size: 8px; font-style: italic; margin-top: 2px; }

table { width: 100%; border-collapse: collapse; margin-top: 4px; }

thead tr th {
    background: #1E3A5F; color: #fff;
    padding: 3px 2px; text-align: center;
    font-size: 7.5px; font-weight: bold;
    border: 0.5pt solid #93c5fd;
}

tbody td { padding: 2px 2px; border: 0.5pt solid #d1d5db; font-size: 7.5px; vertical-align: middle; }
.td-center  { text-align: center; }
.td-right   { text-align: right; white-space: nowrap; }
.td-mono    { font-family: 'DejaVu Sans Mono', monospace; font-size: 7px; }

tr.total-row td { background: #EFF6FF; font-weight: bold; border: 0.5pt solid #93c5fd; }
tr.diff-row td  { font-weight: bold; }
.diff-ok   { color: #166534; }
.diff-bad  { color: #B91C1C; }

.warn { background: #FFF7ED; color: #C2410C; font-weight: bold;
        padding: 4px 8px; border-radius: 3px; margin-bottom: 5px;
        font-size: 8px; border: 0.5pt solid #FCA974; }

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
    <div class="title-main">Bảng kê chứng từ chi tiết</div>
    <div class="title-sub">
      Từ ngày: {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
      &nbsp;&nbsp;—&nbsp;&nbsp;
      Đến ngày: {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
      &nbsp;&nbsp;|&nbsp;&nbsp; Ngày in: {{ now()->format('d/m/Y H:i') }}
    </div>
  </div>

  {{-- Balance warning --}}
  @if(!$isBalanced)
  <div class="warn">
    ⚠ Cảnh báo: Báo cáo đang lệch Nợ/Có ({{ number_format($totals['debit'], 0, ',', '.') }}
    ≠ {{ number_format($totals['credit'], 0, ',', '.') }}) — vui lòng kiểm tra dữ liệu hạch toán.
  </div>
  @endif

  {{-- Table --}}
  <table>
    <thead>
      <tr>
        <th style="width:3%">STT</th>
        <th style="width:6%">NGÀY CT</th>
        <th style="width:7%">SỐ CT</th>
        <th style="width:11%">TÊN KHÁCH/ĐỐI TƯỢNG</th>
        <th style="width:16%">DIỄN GIẢI</th>
        <th style="width:5%">TK</th>
        <th style="width:11%">TÊN TÀI KHOẢN</th>
        <th style="width:6%">TK ĐỐI ỨNG</th>
        <th style="width:8%">PHÁT SINH NỢ</th>
        <th style="width:8%">PHÁT SINH CÓ</th>
        <th style="width:5%">NGUỒN</th>
        <th style="width:8%">DỰ ÁN</th>
        <th style="width:6%">TRẠNG THÁI</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $i => $row)
      <tr>
        <td class="td-center">{{ $i + 1 }}</td>
        <td class="td-center">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
        <td class="td-center td-mono">{{ $row['je_code'] }}</td>
        <td>{{ $row['object_name'] }}</td>
        <td>{{ $row['description'] }}</td>
        <td class="td-center td-mono">{{ $row['account_code'] }}</td>
        <td>{{ $row['account_name'] }}</td>
        <td class="td-center td-mono">{{ $row['counter_account'] }}</td>
        <td class="td-right">{{ $row['debit']  > 0 ? number_format($row['debit'],  0, ',', '.') : '' }}</td>
        <td class="td-right">{{ $row['credit'] > 0 ? number_format($row['credit'], 0, ',', '.') : '' }}</td>
        <td class="td-center">{{ $row['source_label'] }}</td>
        <td>{{ $row['project_name'] }}</td>
        <td class="td-center">{{ $row['status'] }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="13" style="text-align:center;padding:10px;color:#6b7280">Không có dữ liệu</td>
      </tr>
      @endforelse
      <tr class="total-row">
        <td colspan="8" style="text-align:center">TỔNG CỘNG</td>
        <td class="td-right">{{ number_format($totals['debit'],  0, ',', '.') }}</td>
        <td class="td-right">{{ number_format($totals['credit'], 0, ',', '.') }}</td>
        <td colspan="3"></td>
      </tr>
      <tr class="diff-row">
        <td colspan="8" style="text-align:center">CHÊNH LỆCH</td>
        <td colspan="2" class="td-right {{ $isBalanced ? 'diff-ok' : 'diff-bad' }}">
          {{ number_format($totals['debit'] - $totals['credit'], 0, ',', '.') }}
        </td>
        <td colspan="3"></td>
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
