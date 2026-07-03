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

thead tr th {
    background: #1E3A5F; color: #fff;
    padding: 3px 3px; text-align: center;
    font-size: 8px; font-weight: bold;
    border: 0.5pt solid #93c5fd;
}

tbody td { padding: 2px 3px; border: 0.5pt solid #d1d5db; font-size: 8px; vertical-align: middle; }
.td-center  { text-align: center; }
.td-right   { text-align: right; white-space: nowrap; }
.td-mono    { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5px; }

tr.total-row td { background: #EFF6FF; font-weight: bold; border: 0.5pt solid #93c5fd; }

@page { size: A4 landscape; margin: 0; }
</style>
</head>
<body>
<div class="page">

  <div class="header-wrap">
    <div class="company-block">
      <div class="company-name">{{ $company['name'] ?? '' }}</div>
      <div>{{ $company['address'] ?? '' }}</div>
    </div>
  </div>

  <div class="title-block">
    <div class="title-main">Danh sách công cụ dụng cụ</div>
    <div class="title-sub">{{ $filterDescription }}</div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:3%">STT</th>
        <th style="width:8%">Mã CCDC</th>
        <th style="width:16%">Tên CCDC</th>
        <th style="width:9%">Nhóm</th>
        <th style="width:9%">Bộ phận</th>
        <th style="width:10%">Trạng thái</th>
        <th style="width:11%">Nguyên giá</th>
        <th style="width:11%">Đã phân bổ</th>
        <th style="width:11%">Còn lại</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $i => $row)
      <tr>
        <td class="td-center">{{ $i + 1 }}</td>
        <td class="td-center td-mono">{{ $row['code'] }}</td>
        <td>{{ $row['name'] }}</td>
        <td>{{ $row['category_name'] }}</td>
        <td>{{ $row['department'] }}</td>
        <td class="td-center">{{ $row['status_label'] }}</td>
        <td class="td-right">{{ number_format($row['original_cost'], 0, ',', '.') }}</td>
        <td class="td-right">{{ number_format($row['total_allocated'], 0, ',', '.') }}</td>
        <td class="td-right">{{ number_format($row['total_remaining'], 0, ',', '.') }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="9" style="text-align:center;padding:10px;color:#6b7280">Không có dữ liệu</td>
      </tr>
      @endforelse
      <tr class="total-row">
        <td colspan="6" style="text-align:center">TỔNG CỘNG</td>
        <td class="td-right">{{ number_format($totals['original_cost'], 0, ',', '.') }}</td>
        <td class="td-right">{{ number_format($totals['total_allocated'], 0, ',', '.') }}</td>
        <td class="td-right">{{ number_format($totals['total_remaining'], 0, ',', '.') }}</td>
      </tr>
    </tbody>
  </table>

</div>
</body>
</html>
