@php $fonts = resource_path('fonts'); @endphp
<style>
    /*
     | Shared stylesheet for every generated PDF (dompdf 3).
     | Noto Sans for text, Noto Sans Devanagari as the fallback for Hindi, DejaVu Sans
     | for symbols (✓, ₹ in older viewers). dompdf falls back glyph-by-glyph along the list.
     | Avoid floats inside the fixed header/footer: dompdf then miscounts page height.
     */
    @font-face { font-family: 'Noto Sans'; font-weight: normal; font-style: normal; src: url('{{ $fonts }}/NotoSans-Regular.ttf'); }
    @font-face { font-family: 'Noto Sans'; font-weight: bold; font-style: normal; src: url('{{ $fonts }}/NotoSans-Bold.ttf'); }
    @font-face { font-family: 'Noto Sans'; font-weight: normal; font-style: italic; src: url('{{ $fonts }}/NotoSans-Italic.ttf'); }
    @font-face { font-family: 'Noto Sans Devanagari'; font-weight: normal; src: url('{{ $fonts }}/NotoSansDevanagari-Regular.ttf'); }
    @font-face { font-family: 'Noto Sans Devanagari'; font-weight: bold; src: url('{{ $fonts }}/NotoSansDevanagari-Bold.ttf'); }

    @page { margin: 29mm 15mm 17mm 15mm; }
    body { font-family: 'Noto Sans', 'Noto Sans Devanagari', 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.1; color: #1f2937; }

    /* Running header and footer (repeated on every page). */
    header { position: fixed; top: -23mm; left: 0; right: 0; height: 19mm; border-bottom: 0.8pt solid #233ea6; }
    header table { width: 100%; border-collapse: collapse; }
    header td { vertical-align: middle; padding: 0; }
    header .logo { height: 15mm; }
    header .inst { font-size: 10.5pt; font-weight: bold; color: #111c45; line-height: 1.2; letter-spacing: 0.1pt; }
    header .addr { font-size: 7.5pt; color: #4b5563; margin-top: 0.5mm; }
    header .doc { font-size: 8pt; font-weight: bold; color: #233ea6; letter-spacing: 0.6pt; text-align: right; line-height: 1.3; }
    header .doc-sub { font-size: 7pt; font-weight: normal; color: #4b5563; letter-spacing: 0; }
    footer { position: fixed; bottom: -11mm; left: 0; right: 0; height: 6mm; font-size: 6.75pt; color: #6b7280; border-top: 0.5pt solid #d1d5db; padding-top: 1.2mm; }

    /* Headings: smaller than before, set apart by colour and a hairline, not a heavy band. */
    h1 { font-size: 12pt; color: #111c45; margin: 0 0 2mm; }
    h2 { font-size: 9.5pt; font-weight: bold; color: #111c45; margin: 4mm 0 1.5mm; padding: 0 0 1mm; border-bottom: 0.6pt solid #c7d2fe; page-break-after: avoid; }
    h2 .num { color: #233ea6; }
    h3 { font-size: 8.75pt; font-weight: bold; color: #1f2937; margin: 2.5mm 0 1mm; page-break-after: avoid; }
    p { margin: 0 0 1.6mm; }

    /* A section that is short enough to move to the next page as a whole. */
    .keep { page-break-inside: avoid; }

    /* Tables: fixed layout so column widths hold and long values wrap instead of overflowing. */
    table { width: 100%; border-collapse: collapse; }
    table.grid { table-layout: fixed; }
    table.grid th, table.grid td { border: 0.5pt solid #9ca3af; padding: 1.1mm 1.5mm; vertical-align: top; text-align: left; word-wrap: break-word; overflow-wrap: break-word; }
    table.grid th { background: #eef2ff; color: #1e293b; font-weight: bold; font-size: 7.75pt; line-height: 1.25; }
    table.grid td { font-size: 8.5pt; line-height: 1.02; }
    table.grid th { line-height: 1; }
    table.grid thead { display: table-header-group; }
    /* A heading and a table's header row never end a page on their own: the first row comes with them. */
    table.grid thead { page-break-after: avoid; }
    table.grid tbody tr:first-child { page-break-before: avoid; }
    table.grid tr { page-break-inside: avoid; }
    table.grid td.num, table.grid th.num { text-align: right; }
    table.grid td.c, table.grid th.c { text-align: center; }

    /* Label / value pairs. */
    table.kv { table-layout: fixed; }
    table.kv td { line-height: 1.05; padding: 1mm 1.6mm 1mm 0; vertical-align: top; border-bottom: 0.4pt solid #e5e7eb; word-wrap: break-word; overflow-wrap: break-word; }
    table.kv tr { page-break-inside: avoid; }
    table.kv td.n { width: 6%; color: #6b7280; font-size: 8pt; }
    table.kv td.k { width: 33%; color: #4b5563; font-size: 8.5pt; }
    table.kv td.v { color: #111827; }

    .muted { color: #6b7280; }
    .small { font-size: 7.75pt; }
    .tiny { font-size: 6.75pt; }
    .strong { font-weight: bold; }
    .box { border: 0.6pt solid #9ca3af; padding: 2mm 2.4mm; line-height: 1.15; }
    .pre { white-space: pre-wrap; }
    .yn { font-weight: bold; }
    .yes { color: #b91c1c; }
    .stamp { border: 0.8pt solid #b91c1c; color: #b91c1c; padding: 0.8mm 2mm; display: inline-block; font-weight: bold; font-size: 8pt; }
    .watermark { position: fixed; top: 42%; left: 10%; font-size: 64pt; color: rgba(185, 28, 28, 0.07); transform: rotate(-30deg); }
    .break-all { word-break: break-all; }
    .note { font-size: 7.5pt; color: #6b7280; margin: 2.5mm 0 0; }
</style>
