<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
@include('pdf._font')
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
  .page { padding: 30px 36px; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; border-bottom: 2px solid #2563eb; padding-bottom: 16px; }
  .company-name { font-size: 18px; font-weight: bold; color: #2563eb; }
  .company-info { font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.5; }
  .doc-title { text-align: right; }
  .doc-title h1 { font-size: 20px; font-weight: bold; color: #1f2937; text-transform: uppercase; }
  .doc-code { font-size: 14px; font-weight: bold; color: #2563eb; margin-top: 4px; }
  .meta-grid { display: flex; gap: 24px; margin-bottom: 16px; }
  .meta-box { flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; }
  .meta-box h3 { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; margin-bottom: 8px; letter-spacing: 0.05em; }
  .meta-row { font-size: 12px; margin-bottom: 4px; }
  .meta-row strong { color: #374151; }
  .meta-row span { color: #1f2937; }
  .notes-box { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 12px; margin-bottom: 16px; }
  .notes-box h3 { font-size: 11px; font-weight: bold; color: #92400e; margin-bottom: 6px; }
  .sign-row { display: flex; gap: 24px; margin-top: 30px; }
  .sign-box { flex: 1; text-align: center; border-top: 1px dashed #d1d5db; padding-top: 10px; }
  .sign-box .sign-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #6b7280; }
  .sign-box .sign-name { font-size: 12px; color: #1f2937; margin-top: 40px; }
  @page { size: A4 portrait; margin: 0; }
</style>
</head>
<body>
<div class="page">
    @include('pdf.partials.employee-profile-body', ['employee' => $employee])
    <p style="text-align:right;font-size:10px;font-style:italic;color:#6b7280;margin-top:6px;">
        Ngày lập: {{ \Carbon\Carbon::now()->format('d/m/Y') }}
    </p>
</div>
</body>
</html>
