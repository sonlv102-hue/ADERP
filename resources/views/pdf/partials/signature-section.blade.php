{{--
    Shared signature section for PDF reports (dompdf).
    See docs/REPORTING_STANDARDS.md and .claude/rules/reporting-standards.md.

    Expected variables:
    - signers: array of ['title' => string, 'instruction' => ?string, 'name' => ?string, 'position' => ?string]
    - signingPlace: ?string
    - signingDate: ?\Carbon\Carbon
    - showSigningDate: bool (default true)
--}}
@php
    $showSigningDate = $showSigningDate ?? true;
    $signerCount = max(1, count($signers ?? []));
    $colWidth = number_format(100 / $signerCount, 4, '.', '');
@endphp
<style>
    .report-signature-section {
        margin-top: 18px;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .report-signing-date {
        margin-bottom: 10px;
        text-align: right;
        font-size: 11px;
        font-style: italic;
        white-space: nowrap;
    }
    .report-signature-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
        break-inside: avoid;
    }
    .report-signature-table td {
        border: none !important;
        padding: 0 8px;
        text-align: center;
        vertical-align: top;
    }
    .report-signature-title {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .report-signature-instruction {
        min-height: 14px;
        margin-top: 2px;
        font-size: 9px;
        font-style: italic;
        font-weight: normal;
    }
    .report-signature-space {
        height: 70px;
        position: relative;
    }
    .report-signature-image {
        max-width: 120px;
        max-height: 65px;
    }
    .report-signature-name {
        min-height: 16px;
        font-size: 11px;
        font-weight: 600;
    }
    .report-signature-position {
        margin-top: 2px;
        font-size: 10px;
    }
</style>
<div class="report-signature-section">
    @if($showSigningDate && !empty($signingDate))
        <div class="report-signing-date">
            {{ $signingPlace ? $signingPlace . ', ' : '' }}ngày {{ $signingDate->format('d') }}
            tháng {{ $signingDate->format('m') }}
            năm {{ $signingDate->format('Y') }}
        </div>
    @endif

    <table class="report-signature-table">
        <tr>
            @foreach($signers as $signer)
                <td style="width: {{ $colWidth }}%">
                    <div class="report-signature-title">{{ $signer['title'] }}</div>
                    <div class="report-signature-instruction">{{ $signer['instruction'] ?? '' }}</div>
                    {{-- stamp_image: field accepted for forward-compat, rendering not yet implemented (no report uses it today) --}}
                    <div class="report-signature-space">
                        @if(!empty($signer['signature_image']))
                            <img src="{{ $signer['signature_image'] }}" class="report-signature-image">
                        @endif
                    </div>
                    <div class="report-signature-name">{{ $signer['name'] ?? '' }}</div>
                    @if(!empty($signer['position']))
                        <div class="report-signature-position">{{ $signer['position'] }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
</div>
