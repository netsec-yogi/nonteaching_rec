@php
    $v = fn ($x) => ($x === null || $x === '') ? '—' : $x;
    $d = fn ($x) => $x ? \Carbon\Carbon::parse($x)->format('d-m-Y') : '—';
    $dt = fn ($x) => $x ? \Carbon\Carbon::parse($x)->timezone(config('app.timezone'))->format('d-m-Y H:i') : '—';
    $money = fn ($x) => $x === null ? '—' : 'Rs. '.number_format((float) $x, 2);
    $m = $s['meta'];
    $p = $s['personal'];
@endphp
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Application {{ $m['reference_no'] ?? 'Draft' }}</title>
@include('pdf._styles')
<style>
    /* Page-one identity block: application details beside the photograph space. */
    .ident { width: 100%; border-collapse: collapse; margin-bottom: 1mm; }
    .ident > tbody > tr > td { vertical-align: top; padding: 0; }
    .photo { display: block; margin-left: auto; width: 26mm; height: 31mm; border: 0.6pt solid #9ca3af; object-fit: cover; }
    .photo-box { margin-left: auto; width: 26mm; height: 31mm; border: 0.6pt solid #9ca3af; font-size: 6.5pt; color: #6b7280; text-align: center; padding-top: 11mm; line-height: 1.3; }
    .ref { font-size: 10pt; font-weight: bold; color: #111c45; }
    .declaration-text { text-align: justify; line-height: 1.15; }
</style>
</head>
<body>
@include('pdf._header', [
    'docTitle' => 'APPLICATION FORM',
    'docSub' => 'Non-Teaching Post'.($m['reference_no'] ? ' · '.$m['reference_no'] : ''),
    'footerLeft' => ($m['reference_no'] ? 'Ref. '.$m['reference_no'].'  ·  Version '.$m['version'].'  ·  Submitted '.$dt($m['submitted_at']) : 'DRAFT PREVIEW — not a submitted application').'  ·  Generated '.$dt($m['generated_at']),
])
@if(!empty($draft))<div class="watermark">DRAFT</div>@endif

{{-- Sections 2–4 and reference --}}
<table class="ident"><tr><td>
    <table class="kv">
        <tr><td class="n">2.</td><td class="k">Advt. No.</td><td class="v"><span class="strong">{{ $s['application']['advertisement_number'] }}</span><br><span class="small muted">{{ $s['application']['advertisement_title'] }}</span></td></tr>
        <tr><td class="n">3.</td><td class="k">Date</td><td class="v">{{ $d($s['application']['application_date']) }}</td></tr>
        <tr><td class="n">4.</td><td class="k">Name of the Post Applied for</td><td class="v"><span class="strong">{{ $s['application']['post'] }}</span> ({{ $s['application']['post_code'] }}){{ $s['application']['department'] ? ', '.$s['application']['department'] : '' }}</td></tr>
        <tr><td class="n"></td><td class="k">Application Reference No.</td><td class="v ref">{{ $v($m['reference_no']) }}</td></tr>
    </table>
</td><td style="width: 30mm">@if(!empty($photo))<img class="photo" src="{{ $photo }}" alt="Photograph">@else<div class="photo-box">Photograph uploaded<br>with the online<br>application</div>@endif</td></tr></table>

{{-- Section 1 --}}
<div class="keep">
    <h2><span class="num">1.</span> Details of Demand Draft / NEFT</h2>
    <table class="grid">
                <thead><tr><th style="width: 27%">NEFT UTR / DD No.</th><th style="width: 18%">Date of NEFT / DD</th><th class="num" style="width: 18%">Amount</th><th style="width: 37%">Issuing Bank / Name of Bank</th></tr></thead>
        <tbody>
        @if(($s['payment']['method'] ?? null) === 'exempt')
            <tr><td colspan="4">Fee exempted{{ $s['payment']['exemption_basis'] ? ' — '.$s['payment']['exemption_basis'] : '' }}</td></tr>
        @else
            <tr><td>{{ $v($s['payment']['utr_number']) }}@if($s['payment']['method'])<br><span class="small muted">{{ ['neft' => 'NEFT / RTGS', 'demand_draft' => 'Demand Draft', 'online' => 'Online'][$s['payment']['method']] ?? $s['payment']['method'] }}</span>@endif</td><td>{{ $d($s['payment']['transaction_date']) }}</td><td class="num">{{ $money($s['payment']['amount']) }}</td><td>{{ $v($s['payment']['bank_name']) }}</td></tr>
        @endif
        </tbody>
    </table>
</div>

{{-- Sections 5–16 --}}
<div class="keep">
    <h2><span class="num">5–16.</span> Personal Information</h2>
    <table class="kv">
        <tr><td class="n">5.</td><td class="k">Name in Full (in capital letters)</td><td class="v strong">{{ $v($p['full_name']) }}</td></tr>
        <tr><td class="n">6.</td><td class="k">{{ $p['relation_label'] }}</td><td class="v">{{ $v($p['relation_name']) }}</td></tr>
        <tr><td class="n">7.</td><td class="k">Date &amp; Place of Birth</td><td class="v">{{ $d($p['date_of_birth']) }}, {{ $v($p['place_of_birth']) }}</td></tr>
        <tr><td class="n">8.</td><td class="k">Mobile No. / E-mail Id</td><td class="v">{{ $v($p['mobile']) }} / {{ $v($p['email']) }}</td></tr>
        <tr><td class="n">9.</td><td class="k">Nationality</td><td class="v">{{ $v($p['nationality']) }}</td></tr>
        <tr><td class="n">10.</td><td class="k">Gender</td><td class="v">{{ $v($p['gender']) }}</td></tr>
        <tr><td class="n">11.</td><td class="k">Marital Status</td><td class="v">{{ $v($p['marital_status']) }}</td></tr>
        <tr><td class="n">12.</td><td class="k">Category</td><td class="v">{{ $v($p['category']) }}</td></tr>
        <tr><td class="n">13.</td><td class="k">Whether Physically Handicapped</td><td class="v">{{ $p['is_pwd'] }}@if($p['is_pwd'] === 'Yes') — {{ $v($p['disability_type']) }}{{ $p['disability_percentage'] ? ' ('.$p['disability_percentage'].'%)' : '' }}@endif</td></tr>
        <tr><td class="n">14.</td><td class="k">Nearest Railway Station</td><td class="v">{{ $v($p['nearest_railway_station']) }}</td></tr>
        <tr><td class="n">15.</td><td class="k">Correspondence Address</td><td class="v">{{ $v($p['correspondence_address']) }}</td></tr>
        <tr><td class="n">16.</td><td class="k">Permanent Address</td><td class="v">{{ $v($p['permanent_address']) }}</td></tr>
    </table>
</div>

{{-- Section 17 --}}
<div class="keep">
    <h2><span class="num">17.</span> Ex-Servicemen</h2>
    <table class="kv">
        <tr><td class="k" style="width: 39%">Belongs to the sub-category of Ex-servicemen</td><td class="v yn">{{ $p['is_ex_serviceman'] }}</td></tr>
        @if($p['is_ex_serviceman'] === 'Yes')
            <tr><td class="k">Category</td><td class="v">{{ $v($p['ex_serviceman_category']) }}</td></tr>
            <tr><td class="k">Details</td><td class="v pre">{{ $v($p['ex_serviceman_details']) }}</td></tr>
        @endif
    </table>
</div>

{{-- Section 18 --}}
<h2><span class="num">18.</span> Educational / Professional / Technical Qualifications</h2>
<table class="grid">
        <thead><tr><th style="width: 21%">Examination passed</th><th style="width: 19%">Board / University</th><th style="width: 8.5%">Duration</th><th class="c" style="width: 6.5%">Year</th><th style="width: 11%">Division / % Marks</th><th style="width: 21%">Subject(s) studied</th><th style="width: 13%">Specialization</th></tr></thead>
    <tbody>
    @forelse($s['qualifications'] as $q)
        <tr>
            <td><span class="strong">{{ $q['examination'] }}</span><br><span class="muted small">{{ $q['level'] }}</span></td>
            <td>{{ $q['board_university'] }}</td>
            <td>{{ $v($q['duration']) }}</td>
            <td class="c">{{ $q['year_of_passing'] }}</td>
            <td>{{ $v($q['division']) }}@if($q['percentage'] !== null)<br>{{ number_format($q['percentage'], 2) }}%@endif</td>
            <td>{{ $v($q['subjects']) }}</td>
            <td>{{ $v($q['specialization']) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No qualifications entered.</td></tr>
    @endforelse
    </tbody>
</table>

{{-- Section 19 --}}
<h2><span class="num">19.</span> Details of Employment <span class="muted" style="font-weight: normal">(reverse chronological order)</span></h2>
<table class="grid">
        <thead><tr><th style="width: 19%">Deptt. / Institute</th><th style="width: 13%">Post held</th><th style="width: 11.5%">Nature of appt.</th><th style="width: 13.5%">Period (From – To)</th><th style="width: 9%">Duration</th><th style="width: 15.5%">Scale / Level; Basic / Gross</th><th style="width: 18.5%">Nature of Duty</th></tr></thead>
    <tbody>
    @forelse($s['employments'] as $e)
        <tr>
            <td>{{ $e['organization'] }}</td>
            <td>{{ $e['post_held'] }}</td>
            <td>{{ $e['employment_type'] }}</td>
            <td>{{ $d($e['from_date']) }} –<br>{{ $e['is_current'] ? 'Till date' : $d($e['to_date']) }}</td>
            <td>{{ $e['duration'] }}</td>
            <td>{{ $v($e['pay_scale_level']) }}<br><span class="muted">Basic</span> {{ $money($e['basic_pay']) }}<br><span class="muted">Gross</span> {{ $money($e['gross_pay']) }}</td>
            <td class="pre">{{ $v($e['nature_of_duties']) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No employment history declared.</td></tr>
    @endforelse
    </tbody>
</table>
@if($s['employments'])
    <p class="small" style="margin-top: 1.2mm">Total experience: <span class="strong">{{ intdiv($s['total_experience_months'], 12) }} years {{ $s['total_experience_months'] % 12 }} months</span>. In service at present: <span class="strong">{{ $s['is_in_service'] ? 'Yes' : 'No' }}</span></p>
@endif

{{-- Section 20 --}}
<div class="keep">
    <h2><span class="num">20.</span> Convictions / Pending Proceedings</h2>
    <table class="kv">
        <tr><td class="k" style="width: 82%">{{ $s['section20']['question'] }}</td><td class="v yn {{ $s['section20']['answer'] === 'YES' ? 'yes' : '' }}">{{ $v($s['section20']['answer']) }}</td></tr>
        @if($s['section20']['details'])<tr><td class="v pre" colspan="2"><span class="muted">Details:</span> {{ $s['section20']['details'] }}</td></tr>@endif
    </table>
</div>

{{-- Section 21 --}}
<h2><span class="num">21.</span> Certificates of Excellence / Merit during the Service Period</h2>
@if($s['merit_records'])
    <table class="grid">
                <thead><tr><th style="width: 34%">Certificate / Award</th><th style="width: 28%">Awarded by</th><th style="width: 12%">Date</th><th style="width: 26%">Details</th></tr></thead>
        <tbody>
        @foreach($s['merit_records'] as $r)
            <tr><td>{{ $r['title'] }}</td><td>{{ $v($r['awarded_by']) }}</td><td>{{ $d($r['awarded_on']) }}</td><td class="pre">{{ $v($r['description']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@else <p class="muted">None.</p> @endif

{{-- Section 22 --}}
<h2><span class="num">22.</span> Training Courses Conducted / Attended, Consultancy Services Offered</h2>
@if($s['trainings'])
    <table class="grid">
                <thead><tr><th style="width: 13%">Type</th><th style="width: 30%">Title</th><th style="width: 20%">Organisation</th><th style="width: 13%">From – To</th><th style="width: 9%">Duration</th><th style="width: 15%">Details</th></tr></thead>
        <tbody>
        @foreach($s['trainings'] as $t)
            <tr><td>{{ $t['type'] }}</td><td>{{ $t['title'] }}</td><td>{{ $v($t['organization']) }}</td><td>{{ $d($t['from_date']) }} –<br>{{ $d($t['to_date']) }}</td><td>{{ $v($t['duration']) }}</td><td class="pre">{{ $v($t['description']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@else <p class="muted">None.</p> @endif

{{-- Section 23 --}}
<h2><span class="num">23.</span> Corporate Membership of Professional Institutes</h2>
@if($s['memberships'])
    <table class="grid">
                <thead><tr><th style="width: 36%">Institute</th><th style="width: 26%">Membership category</th><th style="width: 24%">Membership No.</th><th style="width: 14%">Member since</th></tr></thead>
        <tbody>
        @foreach($s['memberships'] as $mb)
            <tr><td>{{ $mb['institute_name'] }}</td><td>{{ $v($mb['membership_category']) }}</td><td class="break-all">{{ $v($mb['membership_number']) }}</td><td>{{ $d($mb['member_since']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@else <p class="muted">None.</p> @endif

{{-- Section 24 --}}
<h2><span class="num">24.</span> Suitability for the Post</h2>
<div class="box pre">{{ $v($s['suitability_statement']) }}</div>

{{-- Section 25 --}}
<div class="keep">
    <h2><span class="num">25.</span> Referees</h2>
    <table class="grid">
                <thead><tr><th style="width: 16%"></th>@foreach($s['referees'] as $r)<th style="width: {{ round(84 / max(count($s['referees']), 1), 2) }}%">{{ $r['position'] }}{{ match($r['position']) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' } }} Referee</th>@endforeach</tr></thead>
        <tbody>
        @foreach(['name' => 'Name', 'designation' => 'Designation', 'organization' => 'Organisation', 'address' => 'Address', 'phone' => 'Phone No', 'email' => 'Email id', 'relationship' => 'Relationship', 'years_known' => 'Years known'] as $key => $label)
            <tr><th>{{ $label }}</th>@foreach($s['referees'] as $r)<td class="{{ $key === 'email' ? 'break-all' : '' }}">{{ $v($r[$key]) }}</td>@endforeach</tr>
        @endforeach
            <tr><th>Present / recent employer or superior</th>@foreach($s['referees'] as $r)<td>{{ $r['is_employer_or_superior'] ? 'Yes' : 'No' }}</td>@endforeach</tr>
            <tr><th>Resident in India</th>@foreach($s['referees'] as $r)<td>{{ ($r['is_resident_of_india'] ?? null) ? 'Yes' : 'No' }}</td>@endforeach</tr>
            <tr><th>Not a relation</th>@foreach($s['referees'] as $r)<td>{{ ($r['is_not_relative'] ?? null) ? 'Yes' : 'No' }}</td>@endforeach</tr>
        </tbody>
    </table>
</div>

{{-- Sections 26–30 --}}
<h2><span class="num">26–30.</span> Declarations</h2>
<table class="grid">
        <thead><tr><th style="width: 9%">No.</th><th style="width: 80%">Question</th><th class="c" style="width: 11%">Answer</th></tr></thead>
    <tbody>
    @foreach($s['declarations'] as $code => $q)
        <tr>
            <td>{{ ['sec26a' => '26 (a)', 'sec26b' => '26 (b)', 'sec27' => '27', 'sec28' => '28', 'sec29' => '29', 'sec30' => '30'][$code] }}</td>
            <td>{{ $q['question'] }}@if($q['details'])<div class="pre muted" style="margin-top: 1mm"><span class="strong">Details:</span> {{ $q['details'] }}</div>@endif</td>
            <td class="c yn">{{ $v($q['answer']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Section 31 --}}
<h2><span class="num">31.</span> Any Other Achievement</h2>
<div class="box pre">{{ $v($s['other_achievements']) }}</div>

{{-- Declaration --}}
<div class="keep">
    <h2>Declaration</h2>
    <p class="declaration-text">{{ $s['candidate_declaration']['text'] }}</p>
    <table class="kv">
        <tr><td class="k">Place</td><td class="v">{{ $v($s['candidate_declaration']['place']) }}</td></tr>
        <tr><td class="k">Date</td><td class="v">{{ $d($s['candidate_declaration']['date']) }}</td></tr>
        <tr><td class="k">Electronically accepted by the candidate</td><td class="v">{{ $dt($s['candidate_declaration']['accepted_at']) }} <span class="muted">(declaration version {{ $v($s['candidate_declaration']['version']) }})</span></td></tr>
    </table>
</div>

{{-- Section 32 --}}
<div class="keep">
    <h2><span class="num">32.</span> Endorsement by the Employer</h2>
    @if($s['endorsement'])
        @php $e = $s['endorsement']; @endphp
        <p class="declaration-text">
            The applicant <span class="strong">{{ trim(($p['salutation'] ?? '').' '.$p['full_name']) }}</span>, who has submitted this application for the post of <span class="strong">{{ $s['application']['post'] }}</span>
            in ABV-Indian Institute of Information Technology and Management, Gwalior, is working in this organisation namely
            <span class="strong">{{ $v($e['employer_organization']) }}</span> as <span class="strong">{{ $v($e['designation']) }}</span>, in a
            <span class="strong">{{ strtolower($v($e['employment_type'])) }}</span> capacity with effect from <span class="strong">{{ $d($e['employment_start_date']) }}</span>
            in the scale of pay/level <span class="strong">{{ $v($e['pay_scale_level']) }}</span>, drawing basic pay of <span class="strong">{{ $money($e['basic_pay']) }}</span>.
            The next increment is due on <span class="strong">{{ $d($e['next_increment_date']) }}</span>.
        </p>
        <table class="kv">
            <tr><td class="k">No disciplinary/vigilance case held, contemplated or pending</td><td class="v">{{ $e['no_disciplinary_case_certified'] ? 'Certified' : 'Not certified' }}</td></tr>
            <tr><td class="k">No objection to the application being considered</td><td class="v">{{ $e['no_objection_certified'] ? 'Certified' : 'Not certified' }}</td></tr>
            <tr><td class="k">Forwarding officer</td><td class="v">{{ $v($e['forwarding_officer_name']) }}, {{ $v($e['forwarding_officer_designation']) }}</td></tr>
            <tr><td class="k">Place / Date</td><td class="v">{{ $v($e['place']) }} / {{ $d($e['endorsed_on']) }}</td></tr>
        </table>
        <p class="small muted" style="margin-top: 1.2mm">The signed and sealed endorsement is enclosed as an uploaded document.</p>
    @else
        <p class="muted">Not applicable — the candidate is not in service, or endorsement is not required under this advertisement.</p>
    @endif
</div>

{{-- Check List --}}
<h2>Check List</h2>
<table class="grid">
        <thead><tr><th class="c" style="width: 7%">S. No</th><th style="width: 61%">Particulars</th><th class="c" style="width: 11%">✓ / X / NA</th><th style="width: 21%">Page No. / Document</th></tr></thead>
    <tbody>
    @foreach($s['checklist'] as $i => $c)
        <tr><td class="c">{{ $i + 1 }}</td><td>{{ $c['label'] }}</td><td class="c strong">{{ $c['status'] }}</td><td>{{ $v($c['page_reference'] ?: $c['document']) }}</td></tr>
    @endforeach
    </tbody>
</table>

@if($m['reference_no'])
<p class="note">
    This document is the official snapshot of application {{ $m['reference_no'] }} (version {{ $m['version'] }}) as submitted on {{ $dt($m['submitted_at']) }}.
    Referee recommendations are confidential and are not part of this document.
</p>
@endif

{{-- Uploaded documents --}}
<h2>Index of Uploaded Documents</h2>
<table class="grid">
        <thead><tr><th class="c" style="width: 5%">#</th><th style="width: 31%">Document type</th><th style="width: 40%">File</th><th class="num" style="width: 11%">Size</th><th style="width: 13%">Uploaded</th></tr></thead>
    <tbody>
    @forelse($s['documents'] as $i => $doc)
        <tr>
            <td class="c">{{ $i + 1 }}</td>
            <td>{{ $doc['type'] }}@if($doc['label'])<br><span class="muted small">{{ $doc['label'] }}</span>@endif</td>
            <td><span class="break-all">{{ $doc['original_name'] }}</span><br><span class="muted tiny break-all">SHA-256 {{ substr($doc['sha256'], 0, 32) }}…</span></td>
            <td class="num" style="white-space: nowrap">{{ number_format($doc['size'] / 1024, 0) }} KB</td>
            <td>{{ $dt($doc['uploaded_at']) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No documents uploaded.</td></tr>
    @endforelse
    </tbody>
</table>


</body></html>
