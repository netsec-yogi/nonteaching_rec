@php
    // Wide exports (e.g. the consolidated one-row-per-application report) cannot be read as an
    // A4 table; they print as one labelled block per record. Spreadsheets keep the row layout.
    $asRecords = count($headings) > 14;
    $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
    $blank = fn ($c) => $c === null || $c === '' || $c === '—';
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{{ $title }}</title>
@include('pdf._styles')
<style>
    table.grid td { font-size: {{ count($headings) > 9 ? '7.5pt' : '8pt' }}; }
    table.grid th { font-size: 7.25pt; }
    .record { margin-bottom: 3mm; }
    .record-head { background: #eef2ff; border: 0.5pt solid #9ca3af; border-bottom: 0; padding: 1.2mm 1.6mm; font-weight: bold; color: #111c45; font-size: 8.5pt; page-break-after: avoid; }
    table.fields td { font-size: 7.75pt; }
    table.fields td.l { color: #4b5563; background: #f8fafc; }
</style>
</head>
<body>
@include('pdf._header', ['docTitle' => 'RECRUITMENT REPORT', 'docSub' => count($rows).' record'.(count($rows) === 1 ? '' : 's').' · '.now()->format('d-m-Y'), 'footerLeft' => $title.'  ·  Generated '.now()->format('d-m-Y H:i')])
<h1>{{ $title }}</h1>
@if($filters || $asRecords)
    <p class="small muted">
        @if($filters)Filters: @foreach($filters as $k => $v){{ str_replace('_', ' ', $k) }}: {{ is_array($v) ? implode(', ', $v) : $v }}@if(!$loop->last); @endif @endforeach.@endif
        @if($asRecords) Each record is printed as a block of fields; fields that are blank in the source are omitted. The Excel and CSV exports keep one row per record.@endif
    </p>
@endif

@if($asRecords)
    @forelse($rows as $row)
        @php
            $pairs = collect($headings)->zip($row)->reject(fn ($p) => $blank($p[1]))->values();
            $lines = $pairs->chunk(2);
        @endphp
        <div class="record">
            <div class="record-head">{{ $blank($row[0] ?? null) || ($row[0] ?? '') === '(draft)' ? 'Draft (no reference number)' : $row[0] }}@if(isset($row[7]) && !$blank($row[7])) — {{ $row[7] }}@endif</div>
            <table class="grid fields">
                @foreach($lines as $line)
                    <tr>
                        @foreach($line as $p)<td class="l" style="width: 19%">{{ $p[0] }}</td><td style="width: 31%">{{ $p[1] }}</td>@endforeach
                        @for($i = $line->count(); $i < 2; $i++)<td class="l" style="width: 19%"></td><td style="width: 31%"></td>@endfor
                    </tr>
                @endforeach
            </table>
        </div>
    @empty
        <p class="muted">No records.</p>
    @endforelse
@else
    <table class="grid">
        <thead><tr>@foreach($headings as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr>@foreach($row as $cell)<td>{{ $blank($cell) ? '—' : $cell }}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($headings) }}" class="muted">No records.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif
</body></html>
