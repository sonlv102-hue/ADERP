@php
$fontDir = storage_path('fonts/');
@endphp
<style>
@font-face {
    font-family: 'DejaVu Sans';
    src: url('{{ $fontDir }}DejaVuSans.ttf') format('truetype');
    font-weight: normal;
    font-style: normal;
}
@font-face {
    font-family: 'DejaVu Sans';
    src: url('{{ $fontDir }}DejaVuSans-Bold.ttf') format('truetype');
    font-weight: bold;
    font-style: normal;
}
@font-face {
    font-family: 'DejaVu Sans';
    src: url('{{ $fontDir }}DejaVuSans-Oblique.ttf') format('truetype');
    font-weight: normal;
    font-style: italic;
}
@font-face {
    font-family: 'DejaVu Sans';
    src: url('{{ $fontDir }}DejaVuSans-BoldOblique.ttf') format('truetype');
    font-weight: bold;
    font-style: italic;
}
</style>
