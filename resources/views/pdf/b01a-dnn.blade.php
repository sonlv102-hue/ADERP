<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #111; }
.page { padding: 12mm 10mm 10mm 15mm; }

.header-wrap { display: flex; justify-content: space-between; margin-bottom: 6px; }
.company-block { font-size: 9px; line-height: 1.5; }
.company-name { font-weight: bold; font-size: 10px; }
.template-block { text-align: right; font-size: 8.5px; font-style: italic; line-height: 1.5; }
.template-code { font-weight: bold; font-size: 10px; font-style: normal; }

.title-block { text-align: center; margin: 8px 0 2px; }
.title-main { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
.title-sub { font-size: 9px; font-style: italic; margin-top: 2px; }
.unit-label { text-align: right; font-size: 8.5px; font-style: italic; margin-bottom: 4px; }

table { width: 100%; border-collapse: collapse; margin-top: 4px; }
thead tr th {
    background: #334155; color: #fff;
    padding: 4px 3px; text-align: center;
    font-size: 8.5px; border: 0.5pt solid #64748b;
}
tbody td { padding: 2.5px 3px; border: 0.5pt solid #d1d5db; font-size: 8px; vertical-align: middle; }
td.code   { text-align: center; width: 32px; }
td.tm     { text-align: center; width: 44px; color: #888; }
td.amount { text-align: right; white-space: nowrap; width: 72px; }
td.label  { padding-left: 4px; }
td.label2 { padding-left: 14px; }

tr.section-header td {
    background: #dbeafe; font-weight: bold; font-size: 8.5px;
    padding: 3px 4px; border: 0.5pt solid #93c5fd;
}
tr.section-header-green td {
    background: #dcfce7; font-weight: bold; font-size: 8.5px;
    padding: 3px 4px; border: 0.5pt solid #86efac;
}
tr.total-row td { background: #eff6ff; font-weight: bold; }
tr.total-row-green td { background: #f0fdf4; font-weight: bold; }
tr.formula-row td { background: #f8fafc; font-weight: bold; }

.sign-section { margin-top: 12px; }
.sign-date { text-align: right; font-size: 8.5px; font-style: italic; margin-bottom: 8px; }
.sign-row { display: flex; justify-content: space-between; }
.sign-box { text-align: center; width: 30%; }
.sign-title { font-size: 8px; font-weight: bold; text-transform: uppercase; }
.sign-note { font-size: 7.5px; font-style: italic; color: #555; }
.sign-name { font-size: 8px; margin-top: 28px; }

@page { size: A4 portrait; margin: 0; }
</style>
</head>
<body>
<div class="page">
    {{-- Header --}}
    <div class="header-wrap">
        <div class="company-block">
            <div class="company-name">{{ $company['company_name'] ?? 'Đơn vị báo cáo' }}</div>
            <div>Địa chỉ: {{ $company['company_address'] ?? '' }}</div>
        </div>
        <div class="template-block">
            <div class="template-code">Mẫu số B01a-DNN</div>
            <div>(Ban hành theo Thông tư số 133/2016/TT-BTC</div>
            <div>ngày 26/8/2016 của Bộ Tài chính)</div>
        </div>
    </div>

    {{-- Title --}}
    <div class="title-block">
        <div class="title-main">Bảng cân đối kế toán</div>
        <div class="title-sub">Tại ngày {{ \Carbon\Carbon::parse($asOf)->format('d/m/Y') }}</div>
    </div>

    <div class="unit-label">Đơn vị tính: Đồng Việt Nam</div>

    @php
        $fmtAmt = function($v) {
            if ($v == 0) return '—';
            if ($v < 0) return '(' . number_format(abs($v), 0, ',', '.') . ')';
            return number_format($v, 0, ',', '.');
        };
        $assetRows  = array_filter($rows, fn($r) => $r['section'] === 'asset');
        $sourceRows = array_filter($rows, fn($r) => $r['section'] === 'source');
    @endphp

    <table>
        <thead>
            <tr>
                <th style="width:44%">CHỈ TIÊU</th>
                <th style="width:8%">Mã số</th>
                <th style="width:8%">Thuyết minh</th>
                <th style="width:20%">Số cuối năm</th>
                <th style="width:20%">Số đầu năm</th>
            </tr>
        </thead>
        <tbody>
            {{-- TÀI SẢN --}}
            <tr class="section-header">
                <td colspan="5">PHẦN I — TÀI SẢN</td>
            </tr>
            @foreach($assetRows as $row)
                @php
                    $trClass = '';
                    if ($row['is_total']) $trClass = 'total-row';
                    elseif ($row['is_formula']) $trClass = 'formula-row';
                    $labelClass = $row['level'] === 2 ? 'label2' : 'label';
                @endphp
                <tr class="{{ $trClass }}">
                    <td class="{{ $labelClass }}">{{ $row['item_name'] }}</td>
                    <td class="code">{{ $row['item_code'] ?? '' }}</td>
                    <td class="tm">{{ $row['thuyetminh'] ?? '' }}</td>
                    <td class="amount">{{ ($row['amount'] != 0 || $row['is_total']) ? $fmtAmt($row['amount']) : '—' }}</td>
                    <td class="amount">{{ (($row['prior_amount'] ?? 0) != 0 || $row['is_total']) ? $fmtAmt($row['prior_amount'] ?? 0) : '—' }}</td>
                </tr>
            @endforeach

            {{-- NGUỒN VỐN --}}
            <tr class="section-header-green">
                <td colspan="5">PHẦN II — NGUỒN VỐN</td>
            </tr>
            @foreach($sourceRows as $row)
                @php
                    $trClass = '';
                    if ($row['is_total']) $trClass = 'total-row-green';
                    elseif ($row['is_section_header']) $trClass = 'section-header-green';
                    elseif ($row['is_formula']) $trClass = 'formula-row';
                    $labelClass = ($row['level'] === 2 && !$row['is_section_header']) ? 'label2' : 'label';
                @endphp
                <tr class="{{ $trClass }}">
                    <td class="{{ $labelClass }}">{{ $row['item_name'] }}</td>
                    <td class="code">{{ $row['item_code'] ?? '' }}</td>
                    <td class="tm">{{ $row['thuyetminh'] ?? '' }}</td>
                    <td class="amount">{{ ($row['amount'] != 0 || $row['is_total']) ? $fmtAmt($row['amount']) : '—' }}</td>
                    <td class="amount">{{ (($row['prior_amount'] ?? 0) != 0 || $row['is_total']) ? $fmtAmt($row['prior_amount'] ?? 0) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Signature --}}
    <div class="sign-section">
        <div class="sign-date">Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ \Carbon\Carbon::parse($asOf)->year }}</div>
        <div class="sign-row">
            <div class="sign-box">
                <div class="sign-title">Người lập biểu</div>
                <div class="sign-note">(Ký, họ tên)</div>
                <div class="sign-name">&nbsp;</div>
            </div>
            <div class="sign-box">
                <div class="sign-title">Kế toán trưởng</div>
                <div class="sign-note">(Ký, họ tên)</div>
                <div class="sign-name">&nbsp;</div>
            </div>
            <div class="sign-box">
                <div class="sign-title">Người đại diện theo pháp luật</div>
                <div class="sign-note">(Ký, họ tên, đóng dấu)</div>
                <div class="sign-name">&nbsp;</div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
