@php
    $dt = fn ($x) => $x ? \Carbon\Carbon::parse($x)->timezone(config('app.timezone'))->format('d-m-Y H:i') : '—';
    $label = fn ($s) => ['pending' => 'Pending', 'verified' => 'Verified', 'not_verified' => 'Not Verified', 'not_applicable' => 'N/A'][$s] ?? $s;
    $h = $report['header'];
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Scrutiny Report {{ $h['reference_no'] }}</title>
@include('pdf._styles')
</head>
<body>
@include('pdf._header', ['docTitle' => 'SCRUTINY REPORT', 'footerLeft' => 'Ref. '.$h['reference_no'].' · Generated '.$dt($report['generated_at'])])

<table class="kv">
    <tr><td class="k">Candidate</td><td><strong>{{ $h['candidate'] }}</strong></td></tr>
    <tr><td class="k">Application Reference No.</td><td>{{ $h['reference_no'] }}</td></tr>
    <tr><td class="k">Advertisement No.</td><td>{{ $h['advertisement_number'] }}</td></tr>
    <tr><td class="k">Post applied for</td><td>{{ $h['post'] }}</td></tr>
    <tr><td class="k">Category</td><td>{{ $h['category'] ?? '—' }}</td></tr>
</table>

<h2>Status Summary</h2>
<table class="grid small">
    <thead><tr><th style="width: 16%">Fee</th><th style="width: 16%">Documents</th><th style="width: 18%">Referee recommendations</th><th style="width: 18%">Employer endorsement</th><th style="width: 16%">Scrutiny</th><th style="width: 16%">Outcome</th></tr></thead>
    <tr>
        <td>{{ $report['status']['fee'] }}</td><td>{{ $report['status']['documents'] }}</td>
        <td>{{ $report['status']['referees'] }}</td><td>{{ $report['status']['endorsement'] }}</td>
        <td>{{ $report['status']['scrutiny'] }}</td><td>{{ $report['status']['outcome'] ?? '—' }}</td>
    </tr>
</table>

<h2>Verification Checklist</h2>
<table class="grid small">
    <thead><tr><th style="width: 5%">#</th><th style="width: 33%">Item</th><th style="width: 13%">Section</th><th style="width: 12%">Status</th><th style="width: 22%">Remarks / Discrepancy</th><th style="width: 15%">Verified by / on</th></tr></thead>
    @foreach($report['items'] as $i => $it)
        <tr>
            <td>{{ $i + 1 }}</td><td>{{ $it['label'] }}@if($it['is_mandatory'])<span class="muted"> *</span>@endif</td>
            <td>{{ ucfirst(str_replace('_', ' ', $it['section'])) }}</td><td><strong>{{ $label($it['status']) }}</strong></td>
            <td>{{ $it['remarks'] }}@if($it['discrepancy_reason'])<br><em>Discrepancy: {{ $it['discrepancy_reason'] }}</em>@endif</td>
            <td>{{ $it['verified_by'] ?? '—' }}<br>{{ $dt($it['verified_at']) }}</td>
        </tr>
    @endforeach
</table>
<p class="small muted">* mandatory item</p>

<h2>Advisory Eligibility Checks</h2>
<table class="grid small">
    <thead><tr><th style="width: 34%">Criterion</th><th style="width: 13%">Essential</th><th style="width: 14%">Result</th><th style="width: 39%">Basis</th></tr></thead>
    @forelse($report['eligibility'] as $c)
        <tr><td>{{ $c['label'] }}</td><td>{{ $c['is_essential'] ? 'Essential' : 'Desirable' }}</td><td>{{ ['met' => 'Met', 'not_met' => 'Not met', 'manual' => 'Check manually'][$c['result']] }}</td><td>{{ $c['basis'] }}</td></tr>
    @empty
        <tr><td colspan="4" class="muted">No eligibility criteria configured for this post.</td></tr>
    @endforelse
</table>

<h2>Observations</h2>
@forelse($report['observations'] as $o)
    <p class="small"><strong>{{ $o['deficiency'] ? 'Deficiency' : 'Observation' }}</strong> ({{ ucfirst($o['section']) }}) — {{ $o['text'] }}
        <span class="muted">— {{ $o['by'] }}, {{ $dt($o['at']) }}{{ $o['resolved_at'] ? ', resolved '.$dt($o['resolved_at']) : '' }}</span></p>
@empty
    <p class="muted small">None recorded.</p>
@endforelse

<h2>Outstanding Deficiencies</h2>
@if($report['deficiencies'])
    <ul class="small">@foreach($report['deficiencies'] as $d)<li>{{ $d }}</li>@endforeach</ul>
@else <p class="muted small">None.</p> @endif

@if(! empty($report['originals_awaited']))
<h2>Originals to be Produced (Signed Undertakings)</h2>
<ul class="small">@foreach($report['originals_awaited'] as $o)<li>{{ $o }}</li>@endforeach</ul>
@endif

<h2>Clarifications</h2>
@forelse($report['clarifications'] as $c)
    <p class="small"><strong>{{ $c['subject'] }}</strong> — issued {{ $dt($c['issued_at']) }}, due {{ $dt($c['due_at']) }}, status {{ ucfirst($c['status']) }}{{ $c['resolution'] ? ': '.$c['resolution'] : '' }}</p>
@empty
    <p class="muted small">None issued.</p>
@endforelse

<h2>Reports and Decisions by Stage</h2>
<table class="grid small">
    <thead><tr><th style="width: 16%">Stage</th><th style="width: 15%">Officer</th><th style="width: 13%">Completeness</th><th style="width: 17%">Recommendation / Decision</th><th style="width: 24%">Reasons</th><th style="width: 15%">Submitted</th></tr></thead>
    @forelse($report['reviews'] as $r)
        <tr><td>{{ $r['stage'] }}</td><td>{{ $r['by'] }}</td><td>{{ $r['completeness'] ?? '—' }}</td><td>{{ $r['recommendation'] ?? $r['decision'] ?? '—' }}</td><td class="pre">{{ $r['reasons'] ?? '—' }}</td><td>{{ $dt($r['submitted_at']) }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted">No stage report submitted yet.</td></tr>
    @endforelse
</table>
</body></html>
