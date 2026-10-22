@php $dt = fn ($x) => $x ? $x->timezone(config('app.timezone'))->format('d-m-Y H:i') : '—'; @endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Acknowledgment {{ $a->reference_no }}</title>
@include('pdf._styles')
</head>
<body>
@include('pdf._header', ['docTitle' => 'ACKNOWLEDGMENT OF APPLICATION', 'footerLeft' => 'Ref. '.$a->reference_no])

<p>This is to acknowledge receipt of the online application described below.</p>
<table class="kv" style="margin-top: 4mm">
    <tr><td class="k">Application Reference No.</td><td><strong style="font-size: 12pt">{{ $a->reference_no }}</strong></td></tr>
    <tr><td class="k">Name of the Applicant</td><td>{{ $a->personal?->full_name }}</td></tr>
    <tr><td class="k">Advertisement No.</td><td>{{ $a->advertisement->number }}</td></tr>
    <tr><td class="k">Post Applied for</td><td>{{ $a->post->name }} ({{ $a->post->code }})</td></tr>
    <tr><td class="k">Submitted on</td><td>{{ $dt($a->submitted_at) }}</td></tr>
    <tr><td class="k">Application version</td><td>{{ $a->version }}{{ $version?->pdf_sha256 ? ' — PDF SHA-256 '.substr($version->pdf_sha256, 0, 32).'…' : '' }}</td></tr>
    <tr><td class="k">Fee details</td><td>
        @if($a->payment?->method === 'exempt') Exempted ({{ $a->payment->exemption_basis ?? 'as per rules' }})
        @elseif($a->payment) Rs. {{ number_format((float) $a->payment->amount, 2) }} — UTR/DD {{ $a->payment->utr_number }} dated {{ $a->payment->transaction_date?->format('d-m-Y') }}, {{ $a->payment->bank_name }}
        @else — @endif
    </td></tr>
    <tr><td class="k">Fee verification</td><td>{{ str_replace('_', ' ', ucfirst($a->fee_status)) }}</td></tr>
    <tr><td class="k">Referees nominated</td><td>{{ $a->referees->count() }}</td></tr>
</table>

<div class="box small" style="margin-top: 6mm">
    <strong>Please note:</strong>
    <ol style="margin: 1mm 0 0 4mm; padding: 0">
        <li>This acknowledgment confirms receipt only. It does not confirm eligibility, which will be determined on scrutiny.</li>
        <li>Fee payment is verified separately by the Recruitment Section.</li>
        <li>Your referees have been sent a secure link to submit a confidential recommendation directly to the Institute.</li>
        <li>Quote the Application Reference No. in all correspondence.</li>
    </ol>
</div>

<p class="small muted" style="margin-top: 8mm">Computer-generated acknowledgment; no signature is required.</p>
</body></html>
