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
.title-year { font-size: 9px; font-style: italic; }
.unit-label { text-align: right; font-size: 8.5px; font-style: italic; margin-bottom: 4px; }

table { width: 100%; border-collapse: collapse; margin-top: 4px; }
thead tr th {
    background: #1E3A5F; color: #fff;
    padding: 4px 3px; text-align: center;
    font-size: 8.5px; border: 0.5pt solid #93c5fd;
}
tbody td { padding: 2.5px 3px; border: 0.5pt solid #d1d5db; font-size: 8.5px; vertical-align: middle; }
td.code { text-align: center; }
td.note { text-align: center; }
td.amount { text-align: right; white-space: nowrap; }
td.label { padding-left: 4px; }
td.label-indent { padding-left: 14px; }

tr.section-header td { background: #EFF6FF; font-weight: bold; padding: 3px 3px; }
tr.summary-row td { background: #F0FDF4; font-weight: bold; }
tr.totals-row td { background: #DBEAFE; font-weight: bold; }
tr.cash-row td { background: #FEF9C3; font-weight: bold; }

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
            <div class="template-code">Mẫu số B03-DNN</div>
            <div>(Ban hành theo Thông tư số 133/2016/TT-BTC</div>
            <div>ngày 26/8/2016 của Bộ Tài chính)</div>
        </div>
    </div>

    {{-- Title --}}
    <div class="title-block">
        <div class="title-main">Báo cáo lưu chuyển tiền tệ</div>
        <div class="title-sub">(Theo phương pháp trực tiếp)</div>
        <div class="title-year">Năm {{ $year }}</div>
    </div>
    @php
        $unitLabel = match($unit) {
            'nghin_dong'  => 'Nghìn đồng',
            'trieu_dong'  => 'Triệu đồng',
            default       => 'Đồng',
        };
        function fmtB03($val) {
            if ($val === null || $val === 0) return '—';
            return number_format(abs($val), 0, ',', '.') . ($val < 0 ? ' *' : '');
        }
        $rows = $report['rows'];
    @endphp
    <div class="unit-label">Đơn vị tính: {{ $unitLabel }}</div>

    {{-- Table --}}
    <table>
        <thead>
            <tr>
                <th style="width:46%">Chỉ tiêu</th>
                <th style="width:8%">Mã số</th>
                <th style="width:10%">Thuyết minh</th>
                <th style="width:18%">Năm nay</th>
                <th style="width:18%">Năm trước</th>
            </tr>
        </thead>
        <tbody>
        @php $currentSection = null; @endphp
        @foreach ($rows as $row)
            @php
                $section = $row['section'];
                if ($section && $section !== $currentSection) {
                    $sectionLabel = match($section) {
                        'I'   => 'I. Lưu chuyển tiền từ hoạt động kinh doanh',
                        'II'  => 'II. Lưu chuyển tiền từ hoạt động đầu tư',
                        'III' => 'III. Lưu chuyển tiền từ hoạt động tài chính',
                        default => $section,
                    };
                    $currentSection = $section;
                } else {
                    $sectionLabel = null;
                }
            @endphp
            @if ($sectionLabel)
            <tr class="section-header">
                <td colspan="5" class="label">{{ $sectionLabel }}</td>
            </tr>
            @endif
            @php
                $trClass = $row['is_summary'] ? (in_array($row['code'], ['50','60','61','70']) ? 'totals-row' : 'summary-row') : '';
                $labelClass = $row['is_summary'] ? 'label' : 'label-indent';
            @endphp
            <tr class="{{ $trClass }}">
                <td class="{{ $labelClass }}">{{ $row['label'] }}</td>
                <td class="code">{{ $row['code'] }}</td>
                <td class="note">{{ $row['note'] ?? '' }}</td>
                <td class="amount">{{ $row['curr'] ? number_format($row['curr'], 0, ',', '.') : ($row['curr'] === 0 ? '—' : '') }}</td>
                <td class="amount">{{ $row['prev'] ? number_format($row['prev'], 0, ',', '.') : ($row['prev'] === 0 ? '—' : '') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{-- Note on negative --}}
    <p style="font-size:8px;font-style:italic;margin-top:3px;">(*) Số âm ghi trong ngoặc đơn.</p>

    {{-- Reconciliation warning --}}
    @if (!$report['reconciliation']['ok'])
    <p style="font-size:8px;color:#dc2626;margin-top:4px;">
        ⚠ Mã 70 ({{ number_format($report['reconciliation']['reported_closing'],0,',','.') }}) chưa khớp số dư TK 111/112 cuối kỳ ({{ number_format($report['reconciliation']['actual_closing'],0,',','.') }}). Chênh lệch: {{ number_format($report['reconciliation']['difference'],0,',','.') }}.
    </p>
    @endif

    {{-- Signature --}}
    <div class="sign-section">
        <div class="sign-date">Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ $year }}</div>
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
