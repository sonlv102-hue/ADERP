<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111; }
.page { padding: 14mm 12mm 10mm 15mm; }

.header-wrap { display: flex; justify-content: space-between; margin-bottom: 6px; }
.company-block { font-size: 9px; line-height: 1.5; }
.company-name { font-weight: bold; font-size: 10px; }
.template-block { text-align: right; font-size: 8.5px; font-style: italic; line-height: 1.5; }
.template-code { font-weight: bold; font-size: 10px; font-style: normal; }

.title-block { text-align: center; margin: 8px 0 4px; }
.title-main { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
.title-sub { font-size: 9px; font-style: italic; margin-top: 2px; }
.unit-label { text-align: right; font-size: 8.5px; font-style: italic; margin-bottom: 4px; }

table { width: 100%; border-collapse: collapse; margin-top: 4px; }
thead tr th {
    background: #1E3A5F; color: #fff;
    padding: 4px 3px; text-align: center;
    font-size: 8.5px; border: 0.5pt solid #93c5fd;
}
tbody td { padding: 2.5px 3px; border: 0.5pt solid #d1d5db; font-size: 8.5px; vertical-align: middle; }
td.code { text-align: center; width: 32px; }
td.note { text-align: center; width: 52px; }
td.amount { text-align: right; white-space: nowrap; width: 80px; }
td.label { padding-left: 4px; }
td.label-sub { padding-left: 14px; font-style: italic; }

tr.summary-row td { background: #F0FDF4; font-weight: bold; }

.sign-section { margin-top: 14px; }
.sign-date { text-align: right; font-size: 8.5px; font-style: italic; margin-bottom: 10px; }
.sign-row { display: flex; justify-content: space-between; }
.sign-box { text-align: center; width: 30%; }
.sign-title { font-size: 8.5px; font-weight: bold; text-transform: uppercase; }
.sign-note { font-size: 8px; font-style: italic; color: #555; }
.sign-name { font-size: 8.5px; margin-top: 30px; }

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
            <div class="template-code">Mẫu số B02-DNN</div>
            <div>(Ban hành theo Thông tư số 133/2016/TT-BTC</div>
            <div>ngày 26/8/2016 của Bộ Tài chính)</div>
        </div>
    </div>

    @php
        $period          = $report['period'] ?? null;
        $comparison      = $report['comparison_period'] ?? null;
        $periodLabel     = $period['label'] ?? ('Năm ' . $report['year']);
        $comparisonLabel = $comparison['label'] ?? 'Kỳ so sánh';
        $fmtDate         = fn($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
    @endphp

    {{-- Title --}}
    <div class="title-block">
        <div class="title-main">Báo cáo kết quả hoạt động kinh doanh</div>
        <div class="title-sub">{{ $periodLabel }}</div>
        @if($period)
            <div class="title-sub">Kỳ báo cáo: Từ ngày {{ $fmtDate($period['date_from']) }} đến ngày {{ $fmtDate($period['date_to']) }}</div>
        @endif
    </div>

    @php
        $unitLbl = match($report['unit']) {
            'nghin_dong'  => 'Nghìn đồng',
            'trieu_dong'  => 'Triệu đồng',
            default       => 'Đồng',
        };
        $fmt = fn($v) => $v != 0 ? number_format(abs($v)) : '—';
        $neg = fn($v) => $v < 0 ? '(' . number_format(abs($v)) . ')' : ($v > 0 ? number_format($v) : '—');
        $rows = collect($report['rows'])->keyBy('code');
    @endphp

    <div class="unit-label">Đơn vị tính: {{ $unitLbl }} · Nguồn số liệu: Bút toán GL đã posted</div>

    {{-- Table --}}
    <table>
        <thead>
            <tr>
                <th style="width:42%">CHỈ TIÊU</th>
                <th style="width:7%">Mã số</th>
                <th style="width:9%">Thuyết minh</th>
                <th style="width:21%">{{ $periodLabel }}</th>
                <th style="width:21%">{{ $comparisonLabel }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['rows'] as $row)
                <tr class="{{ $row['isSummary'] ? 'summary-row' : '' }}">
                    <td class="{{ str_starts_with($row['code'], '23') ? 'label-sub' : 'label' }}">
                        {{ $row['label'] }}
                    </td>
                    <td class="code">{{ $row['code'] }}</td>
                    <td class="note">{{ $row['note'] ?? '' }}</td>
                    <td class="amount">
                        @if(in_array($row['code'], ['02','11','22','24','32','51']))
                            {{ $row['curr'] != 0 ? '(' . number_format(abs($row['curr'])) . ')' : '—' }}
                        @else
                            {{ $neg($row['curr']) }}
                        @endif
                    </td>
                    <td class="amount">
                        @if(in_array($row['code'], ['02','11','22','24','32','51']))
                            {{ $row['prev'] != 0 ? '(' . number_format(abs($row['prev'])) . ')' : '—' }}
                        @else
                            {{ $neg($row['prev']) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Signature --}}
    <div class="sign-section">
        <div class="sign-date">Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ $report['year'] }}</div>
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
