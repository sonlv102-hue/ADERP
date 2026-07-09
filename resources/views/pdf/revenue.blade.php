<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    @include('pdf._font')
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111; line-height: 1.4; }
        .page { padding: 15mm 15mm 15mm 15mm; }

        .header-wrap { display: table; width: 100%; margin-bottom: 15px; }
        .company-block { display: table-cell; width: 60%; font-size: 9px; line-height: 1.5; vertical-align: top; }
        .company-name { font-weight: bold; font-size: 10px; text-transform: uppercase; }
        .template-block { display: table-cell; width: 40%; text-align: right; font-size: 8px; font-style: italic; line-height: 1.4; vertical-align: top; }

        .title-block { text-align: center; margin: 15px 0 10px; }
        .title-main { font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
        .title-sub { font-size: 9px; font-style: italic; margin-top: 3px; }
        
        .meta-info { display: table; width: 100%; margin-bottom: 5px; font-size: 8.5px; font-style: italic; }
        .meta-left { display: table-cell; width: 50%; }
        .meta-right { display: table-cell; width: 50%; text-align: right; }

        table { width: 100%; border-collapse: collapse; margin-top: 5px; margin-bottom: 15px; }
        thead tr th {
            background: #1E3A5F; color: #fff;
            padding: 5px 4px; text-align: center;
            font-size: 8.5px; font-weight: bold;
            border: 0.5pt solid #1E3A5F;
        }
        tbody td { padding: 4px 4px; border: 0.5pt solid #cbd5e1; font-size: 8px; vertical-align: middle; }
        
        td.center { text-align: center; }
        td.right { text-align: right; white-space: nowrap; }
        
        tr.summary-row td { background: #f8fafc; font-weight: bold; border-top: 1pt solid #1E3A5F; border-bottom: 1.5pt double #1E3A5F; }

        /* Đối chiếu */
        .reconciliation-title { font-size: 9.5px; font-weight: bold; color: #1E3A5F; margin-top: 15px; margin-bottom: 5px; text-transform: uppercase; }
        .reconciliation-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .reconciliation-table th { background: #f1f5f9; color: #1e293b; border: 0.5pt solid #cbd5e1; font-size: 8.5px; padding: 4px; text-align: left; }
        .reconciliation-table td { border: 0.5pt solid #cbd5e1; font-size: 8px; padding: 4px; }
        .reconciliation-table tr.highlight td { font-weight: bold; }
        .text-danger { color: #b91c1c; }
        .text-success { color: #15803d; }

        /* Chữ ký */
        .sign-section { margin-top: 25px; page-break-inside: avoid; }
        .sign-date { text-align: right; font-size: 8.5px; font-style: italic; margin-bottom: 10px; }
        .sign-table { width: 100%; border: none; margin-top: 5px; }
        .sign-table td { border: none; text-align: center; width: 33.33%; font-size: 8.5px; vertical-align: top; padding: 0; }
        .sign-title { font-weight: bold; text-transform: uppercase; }
        .sign-note { font-size: 8px; font-style: italic; color: #555; margin-top: 2px; }
        .sign-name { font-weight: bold; margin-top: 50px; }

        @page { size: A4 portrait; margin: 0; }
    </style>
</head>
<body>
<div class="page">
    {{-- Header --}}
    <div class="header-wrap">
        <div class="company-block">
            <div class="company-name">{{ $company['company_name'] ?? 'ĐƠN VỊ BÁO CÁO' }}</div>
            <div>Địa chỉ: {{ $company['company_address'] ?? '' }}</div>
            @if(!empty($company['company_phone']))
                <div>SĐT: {{ $company['company_phone'] }}</div>
            @endif
        </div>
        <div class="template-block">
            <div>Mẫu báo cáo nội bộ</div>
            <div>Hệ thống MiniERP</div>
        </div>
    </div>

    {{-- Title --}}
    <div class="title-block">
        <div class="title-main">Báo cáo Doanh thu</div>
        <div class="title-sub">Kỳ báo cáo: {{ $periodLabel }}</div>
    </div>

    <div class="meta-info">
        <div class="meta-left">Nguồn số liệu: Hóa đơn đã xác nhận / hạch toán</div>
        <div class="meta-right">Ngày xuất: {{ $exportDate }}</div>
    </div>

    @php
        $fmt = fn($v) => $v != 0 ? number_format($v) : '—';
        $fmtDiff = function($v) {
            if ($v == 0) return 'Khớp';
            return ($v > 0 ? '+' : '') . number_format($v);
        };
    @endphp

    {{-- Table data --}}
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">STT</th>
                <th style="width: 15%;">Số chứng từ</th>
                <th style="width: 35%;">Khách hàng</th>
                <th style="width: 12%;">Ngày HĐ</th>
                <th style="width: 15%;">Doanh thu (chưa VAT)</th>
                <th style="width: 12%;">Thuế GTGT</th>
                <th style="width: 15%;">Tổng thanh toán</th>
                <th style="width: 11%;">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoices as $idx => $invoice)
                @php
                    $statusLabel = match ($invoice->status) {
                        'sent'     => 'Đã gửi',
                        'paid'     => 'Đã TT',
                        'overdue'  => 'Quá hạn',
                        'cancelled'=> 'Đã hủy',
                        'draft'    => 'Nháp',
                        default    => $invoice->status,
                    };
                @endphp
                <tr>
                    <td class="center">{{ $idx + 1 }}</td>
                    <td class="center" style="font-family: monospace; font-weight: bold; color: #1E3A5F;">{{ $invoice->code }}</td>
                    <td>{{ $invoice->customer_name ?? 'Khách lẻ' }}</td>
                    <td class="center">{{ $invoice->issue_date ? \Carbon\Carbon::parse($invoice->issue_date)->format('d/m/Y') : '' }}</td>
                    <td class="right">{{ $fmt($invoice->subtotal) }}</td>
                    <td class="right">{{ $fmt($invoice->tax_amount) }}</td>
                    <td class="right">{{ $fmt($invoice->total) }}</td>
                    <td class="center">{{ $statusLabel }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="center" style="padding: 10px; font-style: italic; color: #666;">Không có dữ liệu hóa đơn nào trong kỳ báo cáo.</td>
                </tr>
            @endforelse
            
            <tr class="summary-row">
                <td colspan="4" class="center">TỔNG CỘNG ({{ $summary['count_invoices'] }} HĐ)</td>
                <td class="right">{{ $fmt($summary['total_subtotal']) }}</td>
                <td class="right">{{ $fmt($summary['total_tax']) }}</td>
                <td class="right">{{ $fmt($summary['total_payment']) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    {{-- Đối chiếu --}}
    <div class="reconciliation-title">Đối chiếu số liệu với sổ cái kế toán (Bút toán đã ghi sổ)</div>
    <table class="reconciliation-table">
        <thead>
            <tr>
                <th style="width: 40%;">Chỉ tiêu đối chiếu</th>
                <th style="width: 20%; text-align: right;">Số liệu hóa đơn (1)</th>
                <th style="width: 20%; text-align: right;">Số liệu Sổ cái GL (2)</th>
                <th style="width: 20%; text-align: right;">Chênh lệch (1 - 2)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1. Doanh thu (Tài khoản 511)</td>
                <td style="text-align: right;">{{ $fmt($summary['total_subtotal']) }}</td>
                <td style="text-align: right;">{{ $fmt($glReconcile['gl_revenue']) }}</td>
                <td style="text-align: right; font-weight: bold;" class="{{ $glReconcile['revenue_diff'] == 0 ? 'text-success' : 'text-danger' }}">
                    {{ $fmtDiff($glReconcile['revenue_diff']) }}
                </td>
            </tr>
            <tr>
                <td>2. Thuế GTGT đầu ra (Tài khoản 3331)</td>
                <td style="text-align: right;">{{ $fmt($summary['total_tax']) }}</td>
                <td style="text-align: right;">{{ $fmt($glReconcile['gl_vat']) }}</td>
                <td style="text-align: right; font-weight: bold;" class="{{ $glReconcile['vat_diff'] == 0 ? 'text-success' : 'text-danger' }}">
                    {{ $fmtDiff($glReconcile['vat_diff']) }}
                </td>
            </tr>
        </tbody>
    </table>
    
    @if(!$glReconcile['has_gl_entries'])
        <p style="font-size: 8px; font-style: italic; color: #666; margin-top: -15px; margin-bottom: 15px;">
            * Ghi chú: Chưa phát hiện bút toán ghi sổ nào trong kỳ báo cáo để đối chiếu.
        </p>
    @elseif($glReconcile['revenue_diff'] != 0 || $glReconcile['vat_diff'] != 0)
        <p style="font-size: 8px; font-style: italic; color: #b91c1c; margin-top: -15px; margin-bottom: 15px; font-weight: bold;">
            * Cảnh báo: Có sự lệch số liệu giữa hóa đơn bán hàng và Sổ cái kế toán. Vui lòng kiểm tra lại các bút toán chưa được ghi sổ hoặc các hạch toán thủ công ngoài hóa đơn.
        </p>
    @else
        <p style="font-size: 8px; font-style: italic; color: #15803d; margin-top: -15px; margin-bottom: 15px;">
            * Ghi chú: Số liệu hóa đơn bán hàng hoàn toàn khớp với hạch toán trên Sổ cái kế toán.
        </p>
    @endif

    {{-- Signature --}}
    <div class="sign-section">
        <div class="sign-date">Lập, ngày &nbsp;&nbsp;&nbsp; tháng &nbsp;&nbsp;&nbsp; năm {{ date('Y') }}</div>
        <table class="sign-table">
            <tr>
                <td>
                    <div class="sign-title">Người lập biểu</div>
                    <div class="sign-note">(Ký, họ tên)</div>
                    <div class="sign-name">&nbsp;</div>
                </td>
                <td>
                    <div class="sign-title">Kế toán trưởng</div>
                    <div class="sign-note">(Ký, họ tên)</div>
                    <div class="sign-name">&nbsp;</div>
                </td>
                <td>
                    <div class="sign-title">Người đại diện theo pháp luật</div>
                    <div class="sign-note">(Ký, họ tên, đóng dấu)</div>
                    <div class="sign-name">&nbsp;</div>
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
