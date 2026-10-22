@php
    $a = $r->application;
    $dt = fn ($x) => $x ? $x->timezone(config('app.timezone'))->format('d-m-Y H:i') : '—';
    $render = function ($q) {
        $ans = $q['answer'];
        if ($ans === null || $ans === '') return '—';
        if ($q['type'] === 'yes_no') return ucfirst($ans);
        if ($q['type'] === 'rating') {
            $label = $q['options']['labels'][$ans] ?? null;
            return $ans.' / '.($q['options']['max'] ?? 5).($label ? ' ('.$label.')' : '');
        }
        return $ans;
    };
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Referee Recommendation {{ $r->reference_no }}</title>
@include('pdf._styles')
</head>
<body>
@include('pdf._header', ['docTitle' => 'CONFIDENTIAL REFEREE RECOMMENDATION', 'footerLeft' => 'CONFIDENTIAL · '.$r->reference_no])

<p><span class="stamp">CONFIDENTIAL — for the authorised Recruitment Section and Screening Committee only</span></p>

<table class="kv">
    <tr><td class="k">Recommendation reference</td><td><strong>{{ $r->reference_no }}</strong></td></tr>
    <tr><td class="k">Advertisement No.</td><td>{{ $a->advertisement->number }}</td></tr>
    <tr><td class="k">Applicant</td><td>{{ $a->personal?->full_name }} (Application {{ $a->reference_no }})</td></tr>
    <tr><td class="k">Post applied for</td><td>{{ $a->post->name }}</td></tr>
    <tr><td class="k">Referee</td><td>Referee {{ $r->referee->position }}</td></tr>
</table>

<h2>A. Referee Information</h2>
<table class="kv">
    <tr><td class="k">Name</td><td>{{ $r->referee_name }}</td></tr>
    <tr><td class="k">Designation</td><td>{{ $r->referee_designation }}</td></tr>
    <tr><td class="k">Organisation</td><td>{{ $r->referee_organization }}</td></tr>
    <tr><td class="k">Email / Phone</td><td>{{ $r->referee_email }} / {{ $r->referee_phone ?? '—' }}</td></tr>
    <tr><td class="k">Professional relationship with the applicant</td><td>{{ $r->relationship }}</td></tr>
    <tr><td class="k">Known the applicant for</td><td>{{ $r->known_duration }}</td></tr>
    <tr><td class="k">Nature of association</td><td class="pre">{{ $r->nature_of_association ?? '—' }}</td></tr>
</table>
@php $ce = $r->candidate_entered; @endphp
@if(($ce['name'] ?? null) !== $r->referee_name || ($ce['designation'] ?? null) !== $r->referee_designation || ($ce['organization'] ?? null) !== $r->referee_organization || ($ce['email'] ?? null) !== $r->referee_email)
    <p class="small muted">As entered by the applicant: {{ $ce['name'] ?? '' }}, {{ $ce['designation'] ?? '' }}, {{ $ce['organization'] ?? '' }}, {{ $ce['email'] ?? '' }}.</p>
@endif

<h2>B. Professional Assessment</h2>
<table class="grid">
    <tr><th style="width:6%">#</th><th>Question</th><th style="width:34%">Response</th></tr>
    @foreach($r->answers as $i => $q)
        <tr><td>{{ $i + 1 }}</td><td>{{ $q['label'] }}</td><td class="pre">{{ $render($q) }}</td></tr>
    @endforeach
</table>

<h2>Overall Recommendation</h2>
<p style="font-size: 12pt"><strong>{{ $r->overall_recommendation }}</strong></p>
<h3>Additional comments</h3>
<div class="box pre">{{ $r->comments ?: '—' }}</div>

@if($r->amendments->isNotEmpty())
    <h2>Recorded Amendments</h2>
    <p class="small">The original responses above are unaltered. The following amendments were recorded afterwards:</p>
    <table class="grid small">
        <tr><th>Field</th><th>Original</th><th>Amended</th><th>Reason</th><th>By / On</th></tr>
        @foreach($r->amendments as $am)
            <tr><td>{{ $am->field }}</td><td>{{ $am->original_value }}</td><td>{{ $am->amended_value }}</td><td>{{ $am->reason }}</td><td>{{ $am->amender?->name }}<br>{{ $dt($am->created_at) }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Authenticity</h2>
<table class="kv small">
    <tr><td class="k">Submitted at</td><td>{{ $dt($r->submitted_at) }}</td></tr>
    <tr><td class="k">Submitted through</td><td>Unique invitation link sent to {{ $r->invitation?->email ?? $r->referee_email }}</td></tr>
    <tr><td class="k">Content fingerprint (SHA-256)</td><td style="font-family: monospace; font-size: 7.5pt">{{ $r->content_hash }}</td></tr>
</table>
<p class="small muted">The referee confirmed that the information was accurate and submitted in good faith. This recommendation is advisory and does not by itself determine the outcome of the application.</p>
</body></html>
