<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #111; }
html { margin: 12mm 12mm 10mm 15mm; } /* lề trang mọi trang (dompdf lấy margin của html) */
.page-break { page-break-after: always; }

.co-table { width: 100%; border-collapse: collapse; }
.co-table td { vertical-align: top; border: none; padding: 0; }
.co-name { font-weight: bold; font-size: 10.5px; text-transform: uppercase; }
.co-line { font-size: 9px; line-height: 1.5; }
.doc-no { text-align: right; font-size: 9px; line-height: 1.6; }

.title-block { text-align: center; margin: 12px 0 8px; }
.title-main { font-size: 16px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; }
.title-sub { font-size: 9.5px; font-style: italic; margin-top: 3px; }
.status-note { margin-top: 4px; font-size: 9px; font-weight: bold; color: #B91C1C; }

.info { margin: 6px 0 8px; font-size: 9.5px; line-height: 1.6; }

table.lines { width: 100%; border-collapse: collapse; }
table.lines th {
    background: #1E3A5F; color: #fff; font-size: 8.5px; font-weight: bold;
    padding: 4px 3px; text-align: center; border: 0.5pt solid #93c5fd;
}
table.lines td { padding: 3px 4px; border: 0.5pt solid #9ca3af; font-size: 8.5px; vertical-align: top; }
.c { text-align: center; }
.r { text-align: right; white-space: nowrap; }
tr.total td { background: #EFF6FF; font-weight: bold; }

.words { margin-top: 6px; font-size: 9.5px; }
.notes { margin-top: 4px; font-size: 9px; }
</style>
</head>
<body>
@foreach($vouchers as $v)
<div class="voucher {{ $loop->last ? '' : 'page-break' }}">

  <table class="co-table">
    <tr>
      <td style="width:65%">
        <div class="co-name">{{ $company['name'] }}</div>
        @if($company['address'])<div class="co-line">Địa chỉ: {{ $company['address'] }}</div>@endif
        @if($company['tax_code'])<div class="co-line">MST: {{ $company['tax_code'] }}</div>@endif
      </td>
      <td class="doc-no">
        Số: <strong>{{ $v['code'] }}</strong><br>
        {{ $v['is_auto'] ? 'Bút toán tự động' : 'Bút toán thủ công' }}
      </td>
    </tr>
  </table>

  <div class="title-block">
    <div class="title-main">Phiếu kế toán</div>
    <div class="title-sub">
      Ngày {{ $v['entry_date']->format('d') }} tháng {{ $v['entry_date']->format('m') }} năm {{ $v['entry_date']->format('Y') }}
    </div>
    @if($v['status'] !== 'posted')
      <div class="status-note">Trạng thái: {{ $v['status_label'] }}{{ $v['status'] === 'draft' ? ' — chưa hạch toán' : '' }}</div>
    @endif
  </div>

  <div class="info"><strong>Diễn giải:</strong> {{ $v['description'] }}</div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width:5%">STT</th>
        <th style="width:9%">Số hiệu TK</th>
        <th style="width:20%">Tên tài khoản</th>
        <th>Diễn giải</th>
        <th style="width:15%">Đối tượng</th>
        <th style="width:13%">Nợ</th>
        <th style="width:13%">Có</th>
      </tr>
    </thead>
    <tbody>
      @foreach($v['lines'] as $i => $l)
      <tr>
        <td class="c">{{ $i + 1 }}</td>
        <td class="c">{{ $l['account_code'] }}</td>
        <td>{{ $l['account_name'] }}</td>
        <td>{{ $l['description'] }}</td>
        <td>{{ $l['object'] }}</td>
        <td class="r">{{ $l['debit'] > 0 ? number_format($l['debit'], 0, ',', '.') : '' }}</td>
        <td class="r">{{ $l['credit'] > 0 ? number_format($l['credit'], 0, ',', '.') : '' }}</td>
      </tr>
      @endforeach
      <tr class="total">
        <td colspan="5" class="c">TỔNG CỘNG</td>
        <td class="r">{{ number_format($v['total_debit'], 0, ',', '.') }}</td>
        <td class="r">{{ number_format($v['total_credit'], 0, ',', '.') }}</td>
      </tr>
    </tbody>
  </table>

  <div class="words"><strong>Số tiền bằng chữ:</strong> <em>{{ $v['amount_words'] }}</em></div>
  @if($v['notes'])
    <div class="notes"><strong>Ghi chú:</strong> {{ $v['notes'] }}</div>
  @endif

  {{-- Signature (shared component — see docs/REPORTING_STANDARDS.md) --}}
  @include('pdf.partials.signature-section', [
      'signingPlace' => $signingPlace,
      'signingDate'  => $v['entry_date'],
      'signers'      => $signers[$v['id']],
  ])
</div>
@endforeach
</body>
</html>
