<?php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\ExportLog;
use App\Models\Post;
use App\Models\Qualification;
use App\Models\RefereeResponse;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Advertisement- and post-level reports. Each report is a title, headings
 * and a row generator over the user's access scope, so the screen, Excel,
 * CSV and PDF outputs are always the same data.
 */
class ReportService
{
    /** key => [title, kind, sensitive] */
    public const REPORTS = [
        'total' => ['Total applications received', 'aggregate', false],
        'by_advertisement' => ['Applications by advertisement', 'aggregate', false],
        'by_post' => ['Applications by post', 'aggregate', false],
        'by_category' => ['Category-wise applications', 'aggregate', false],
        'by_gender' => ['Gender-wise applications', 'aggregate', false],
        'by_qualification' => ['Applications by highest qualification', 'aggregate', false],
        'by_experience' => ['Applications by employment experience', 'aggregate', false],
        'fee_status' => ['Fee payment status', 'list', false],
        'missing_documents' => ['Missing document report', 'list', false],
        'incomplete' => ['Incomplete application report', 'list', false],
        'scrutiny_pending' => ['Scrutiny pending report', 'list', false],
        'scrutiny_outcome' => ['Scrutiny outcome report', 'list', false],
        'referee_pending' => ['Referee recommendation pending report', 'list', false],
        'referee_completed' => ['Referee recommendation completed report', 'list', false],
        'referee_all_three' => ['Applications with all three recommendations completed', 'list', false],
        'endorsement_pending' => ['Employer endorsement pending report', 'list', false],
        'committee_decisions' => ['Screening Committee decision report', 'list', false],
        'clarification_required' => ['Applications requiring clarification', 'list', false],
        'date_range' => ['Applications received within a date range', 'list', false],
        'vacancy_stats' => ['Post-wise vacancy and application statistics', 'aggregate', false],
        'consolidated' => ['Consolidated application export (one row per application)', 'list', true],
        'referee_content' => ['Referee recommendation content (confidential)', 'list', true],
    ];

    public function __construct(
        private ApplicationFilter $filter,
        private RequirementResolver $requirements,
        private PdfService $pdf,
        private AuditLogger $audit,
    ) {}

    public function definitions(User $user): array
    {
        return collect(self::REPORTS)
            ->filter(fn ($def, $key) => $this->permitted($user, $key))
            ->map(fn ($def, $key) => ['key' => $key, 'title' => $def[0], 'kind' => $def[1], 'sensitive' => $def[2]])
            ->values()->all();
    }

    public function permitted(User $user, string $key): bool
    {
        return match ($key) {
            'referee_content' => $user->hasPermission('exports.referee_content'),
            'consolidated' => $user->hasPermission('exports.applications'),
            default => $user->hasPermission('reports.view'),
        };
    }

    /** @return array{title: string, headings: list<string>, rows: Generator} */
    public function build(string $key, array $filters, User $user): array
    {
        abort_unless(isset(self::REPORTS[$key]), 404);
        abort_unless($this->permitted($user, $key), 403);

        $base = fn () => $this->filter->query($user, $filters);
        $submitted = fn () => $base()->whereNotNull('applications.submitted_at')->where('applications.status', '!=', Application::WITHDRAWN);

        [$headings, $rows] = match ($key) {
            'total' => $this->totals($submitted(), $base()),
            'by_advertisement' => $this->groupCount($submitted()->join('advertisements', 'advertisements.id', '=', 'applications.advertisement_id'),
                "CONCAT(advertisements.number, ' — ', advertisements.title)", 'Advertisement'),
            'by_post' => $this->groupCount($submitted()->join('posts', 'posts.id', '=', 'applications.post_id'), "CONCAT(posts.code, ' — ', posts.name)", 'Post'),
            'by_category' => $this->groupCount($submitted()->join('application_personal_details as pd', 'pd.application_id', '=', 'applications.id'), 'pd.category', 'Category'),
            'by_gender' => $this->groupCount($submitted()->join('application_personal_details as pd', 'pd.application_id', '=', 'applications.id'), 'pd.gender', 'Gender'),
            'by_qualification' => $this->byQualification($submitted()),
            'by_experience' => $this->byExperience($submitted()),
            'fee_status' => $this->list($submitted(), ['Fee status', 'Method', 'UTR / DD No.', 'Date', 'Amount', 'Amount due', 'Bank'],
                fn (Application $a) => [$this->label($a->fee_status), $a->payment?->method, $a->payment?->utr_number,
                    $a->payment?->transaction_date?->format('d-m-Y'), $a->payment?->amount, $a->payment?->amount_due, $a->payment?->bank_name], ['payment']),
            'missing_documents' => $this->missingDocuments($submitted()),
            'incomplete' => $this->list($base()->where(fn ($q) => $q->whereIn('applications.status', [Application::DRAFT, Application::REOPENED])->orWhere('applications.outcome', 'incomplete')),
                ['Status', 'Outcome', 'Last updated'], fn (Application $a) => [$this->label($a->status), $this->label($a->outcome), $a->updated_at?->format('d-m-Y H:i')]),
            'scrutiny_pending' => $this->list($submitted()->where(fn ($q) => $q->whereNull('applications.scrutiny_status')->orWhereNotIn('applications.scrutiny_status', ['decided'])),
                ['Scrutiny status', 'Current stage', 'Submitted'], fn (Application $a) => [$this->label($a->scrutiny_status), $a->currentStage?->name, $a->submitted_at?->format('d-m-Y')], ['currentStage']),
            'scrutiny_outcome' => $this->list($submitted()->whereNotNull('applications.outcome'), ['Outcome', 'Decided'],
                fn (Application $a) => [$this->label($a->outcome), $a->updated_at?->format('d-m-Y')]),
            'referee_pending' => $this->refereeList($submitted()->whereIn('applications.referee_status', ['not_started', 'pending', 'partial'])),
            'referee_completed' => $this->refereeList($submitted()->whereIn('applications.referee_status', ['complete', 'exception'])),
            'referee_all_three' => $this->refereeList($submitted()->whereHas('refereeResponses', null, '>=', 3)),
            'endorsement_pending' => $this->list($submitted()->whereIn('applications.endorsement_status', ['pending', 'submitted']),
                ['Endorsement status', 'Employer', 'Forwarding officer'], fn (Application $a) => [$this->label($a->endorsement_status), $a->endorsement?->employer_organization, $a->endorsement?->forwarding_officer_name], ['endorsement']),
            'committee_decisions' => $this->list($submitted()->whereNotNull('applications.committee_status'), ['Committee status', 'Decision reasons', 'Decided on'],
                fn (Application $a) => [$this->label($a->committee_status), $a->committeeDecisions->first()?->reasons, $a->committeeDecisions->first()?->decided_at?->format('d-m-Y')], ['committeeDecisions']),
            'clarification_required' => $this->list($submitted()->where(fn ($q) => $q->where('applications.scrutiny_status', 'clarification_required')
                ->orWhereHas('clarificationRequests', fn ($c) => $c->whereIn('status', ['open', 'responded']))),
                ['Scrutiny status', 'Open requests', 'Earliest due'], fn (Application $a) => [$this->label($a->scrutiny_status),
                    $a->clarificationRequests->whereIn('status', ['open', 'responded'])->count(),
                    $a->clarificationRequests->whereIn('status', ['open', 'responded'])->min('due_at')?->format('d-m-Y')], ['clarificationRequests']),
            'date_range' => $this->list($submitted(), ['Submitted', 'Status'], fn (Application $a) => [$a->submitted_at?->format('d-m-Y H:i'), $this->label($a->status)]),
            'vacancy_stats' => $this->vacancyStats($filters, $user),
            'consolidated' => $this->consolidated($base()),
            'referee_content' => $this->refereeContent($submitted()),
        };

        return ['title' => self::REPORTS[$key][0], 'headings' => $headings, 'rows' => $rows];
    }

    /** First page for the screen. */
    public function preview(string $key, array $filters, User $user, int $limit = 200): array
    {
        $report = $this->build($key, $filters, $user);
        $rows = [];
        foreach ($report['rows'] as $row) {
            if (count($rows) >= $limit) {
                return [...$report, 'rows' => $rows, 'truncated' => true];
            }
            $rows[] = $row;
        }

        return [...$report, 'rows' => $rows, 'truncated' => false];
    }

    public function export(string $key, string $format, array $filters, User $user): Response
    {
        $report = $this->build($key, $filters, $user);
        $sensitive = self::REPORTS[$key][2];
        $name = str_replace('_', '-', $key).'-'.now()->format('Ymd-His');
        $logFilters = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        $log = fn (int $count) => ExportLog::create([
            'user_id' => $user->id, 'report' => $key, 'format' => $format, 'filters' => $logFilters,
            'row_count' => $count, 'is_sensitive' => $sensitive, 'ip_address' => request()->ip(),
        ]);
        if ($sensitive) {
            $this->audit->record('export.sensitive', null, [], [], ['report' => $key, 'format' => $format, 'filters' => $logFilters], user: $user);
        }

        if ($format === 'pdf') {
            $rows = iterator_to_array($report['rows'], false);
            $log(count($rows));

            return response($this->pdf->table($report['title'], $report['headings'], $rows, $logFilters), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return new StreamedResponse(function () use ($report, $format, $log) {
            $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($report['headings']));
            $count = 0;
            foreach ($report['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $this->safeCell($v), $row)));
                $count++;
            }
            $writer->close();
            $log($count);
        }, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'.'.($format === 'csv' ? 'csv' : 'xlsx').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Defuse spreadsheet formula injection in candidate-supplied text. */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    // ------------------------------------------------------------ builders

    private function label(?string $v): string
    {
        return $v ? ucfirst(str_replace('_', ' ', $v)) : '—';
    }

    private const LIST_HEAD = ['Reference No.', 'Candidate', 'Advertisement', 'Post', 'Category'];

    private function list(Builder $q, array $extraHeadings, callable $extra, array $with = []): array
    {
        $rows = (function () use ($q, $extra, $with) {
            foreach ($q->with(['personal', 'post', 'advertisement', ...$with])->lazyById(500, 'applications.id', 'id') as $a) {
                yield [$a->reference_no ?? '(draft)', $a->personal?->full_name, $a->advertisement->number, $a->post->name, $a->personal?->category, ...$extra($a)];
            }
        })();

        return [[...self::LIST_HEAD, ...$extraHeadings], $rows];
    }

    private function totals(Builder $submitted, Builder $all): array
    {
        $rows = (function () use ($submitted, $all) {
            yield ['Applications started (all statuses)', (clone $all)->count()];
            yield ['Drafts not yet submitted', (clone $all)->where('applications.status', Application::DRAFT)->count()];
            yield ['Submitted', (clone $submitted)->count()];
            yield ['Reopened for correction', (clone $all)->where('applications.status', Application::REOPENED)->count()];
            yield ['Withdrawn', (clone $all)->where('applications.status', Application::WITHDRAWN)->count()];
            foreach (['recommended', 'incomplete', 'not_recommended'] as $o) {
                yield ['Scrutiny outcome: '.$this->label($o), (clone $submitted)->where('applications.outcome', $o)->count()];
            }
        })();

        return [['Measure', 'Count'], $rows];
    }

    private function groupCount(Builder $q, string $expression, string $heading): array
    {
        $data = $q->reorder()->select(DB::raw("$expression as label"), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw($expression))->orderByDesc('total')->toBase()->get();

        return [[$heading, 'Applications'], (function () use ($data) {
            foreach ($data as $r) {
                yield [$r->label ?? 'Not stated', (int) $r->total];
            }
        })()];
    }

    private function byQualification(Builder $q): array
    {
        $rank = ['class_10' => 1, 'class_12' => 2, 'diploma' => 3, 'graduation' => 4, 'post_graduation' => 5, 'doctorate' => 6];
        $counts = [];
        foreach ($q->with('qualifications:id,application_id,level')->lazyById(500, 'applications.id', 'id') as $a) {
            $best = $a->qualifications->sortByDesc(fn ($x) => $rank[$x->level] ?? 0)->first();
            $label = $best ? (Qualification::LEVELS[$best->level] ?? $best->level) : 'Not stated';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);

        return [['Highest qualification', 'Applications'], (function () use ($counts) {
            foreach ($counts as $label => $n) {
                yield [$label, $n];
            }
        })()];
    }

    private function byExperience(Builder $q): array
    {
        $bands = ['No experience' => 0, 'Under 1 year' => 0, '1–3 years' => 0, '3–5 years' => 0, '5–10 years' => 0, '10 years or more' => 0];
        foreach ($q->withSum('employments as months', 'duration_months')->lazyById(500, 'applications.id', 'id') as $a) {
            $m = (int) $a->months;
            $band = match (true) {
                $m === 0 => 'No experience', $m < 12 => 'Under 1 year', $m < 36 => '1–3 years',
                $m < 60 => '3–5 years', $m < 120 => '5–10 years', default => '10 years or more',
            };
            $bands[$band]++;
        }

        return [['Experience', 'Applications'], (function () use ($bands) {
            foreach ($bands as $label => $n) {
                yield [$label, $n];
            }
        })()];
    }

    private function missingDocuments(Builder $q): array
    {
        $rows = (function () use ($q) {
            foreach ($q->with(['personal', 'post.advertisement', 'advertisement', 'employments', 'declarations', 'documents'])->lazyById(200, 'applications.id', 'id') as $a) {
                $missing = $this->requirements->missingDocuments($a);
                $rejected = $a->documents->where('verification_status', 'rejected');
                if ($missing->isEmpty() && $rejected->isEmpty()) {
                    continue;
                }
                yield [$a->reference_no, $a->personal?->full_name, $a->advertisement->number, $a->post->name, $a->personal?->category,
                    $missing->map(fn ($r) => $r->documentType->name)->implode('; '),
                    $rejected->map(fn ($d) => $d->original_name.($d->verification_remarks ? ' ('.$d->verification_remarks.')' : ''))->implode('; ')];
            }
        })();

        return [[...self::LIST_HEAD, 'Missing mandatory documents', 'Documents marked deficient'], $rows];
    }

    private function refereeList(Builder $q): array
    {
        $headings = [...self::LIST_HEAD, 'Referee status'];
        foreach ([1, 2, 3] as $i) {
            array_push($headings, "Referee $i", "Referee $i email", "Referee $i invitation", "Referee $i recommendation", "Referee $i submitted");
        }

        $rows = (function () use ($q) {
            foreach ($q->with(['personal', 'post', 'advertisement', 'referees.invitations', 'referees.response:id,referee_id,submitted_at'])->lazyById(300, 'applications.id', 'id') as $a) {
                $row = [$a->reference_no, $a->personal?->full_name, $a->advertisement->number, $a->post->name, $a->personal?->category, $this->label($a->referee_status)];
                foreach ([1, 2, 3] as $pos) {
                    $r = $a->referees->firstWhere('position', $pos);
                    $inv = $r?->invitations->first();
                    array_push($row, $r?->name, $r?->email, $this->label($inv?->delivery_status ?? 'not_sent'),
                        $r?->response ? 'Completed' : $this->label($inv?->recommendationStatus() ?? 'not invited'),
                        $r?->response?->submitted_at?->format('d-m-Y H:i'));
                }
                yield $row;
            }
        })();

        return [$headings, $rows];
    }

    private function vacancyStats(array $filters, User $user): array
    {
        $posts = Post::with(['advertisement', 'vacancies'])
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->whereIn('advertisement_id',
                app(ApplicationAccess::class)->scope(Application::query(), $user)->select('applications.advertisement_id')))
            ->when($filters['advertisement_id'] ?? null, fn ($q, $v) => $q->where('advertisement_id', $v))
            ->when($filters['post_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->orderBy('advertisement_id')->orderBy('sort_order')->get();

        $rows = (function () use ($posts, $user) {
            foreach ($posts as $post) {
                $q = fn () => $this->filter->query($user, ['post_id' => $post->id]);
                $submitted = $q()->whereNotNull('applications.submitted_at')->where('applications.status', '!=', Application::WITHDRAWN)->count();
                yield [
                    $post->advertisement->number, $post->code, $post->name, $post->department, $post->pay_level,
                    $post->total_vacancies,
                    $post->vacancies->map(fn ($v) => $v->category.': '.$v->count)->implode(', '),
                    $q()->count(), $submitted,
                    $q()->where('applications.outcome', 'recommended')->count(),
                    $post->total_vacancies ? round($submitted / $post->total_vacancies, 1) : null,
                ];
            }
        })();

        return [['Advertisement', 'Post code', 'Post', 'Department', 'Pay level', 'Vacancies', 'Category-wise vacancies',
            'Applications started', 'Submitted', 'Recommended at scrutiny', 'Applicants per vacancy'], $rows];
    }

    /**
     * One row per application covering every section of the paper form. Repeatable sections are
     * flattened to numbered column groups (qualifications, employments, referees); the shorter
     * optional lists (21–23) are joined into one cell each so nothing is dropped.
     */
    private function consolidated(Builder $q): array
    {
        $maxQ = 6;
        $maxE = 5;
        // Column groups for as many referees as any advertisement asks for (3 on the paper form, up to 5).
        $maxR = max(3, min(5, (int) Advertisement::max('referees_required')));
        $decl = ['sec20' => 'Sec 20 conviction / case / enquiry', 'sec26a' => 'Sec 26(a) arrested / prosecuted / debarred', 'sec26b' => 'Sec 26(b) case pending',
            'sec27' => 'Sec 27 medically unfit / resigned / dismissed', 'sec28' => 'Sec 28 previously applied', 'sec29' => 'Sec 29 through proper channel', 'sec30' => 'Sec 30 ACR/APAR enclosed'];
        $yn = fn ($v) => $v === null ? null : ($v ? 'Yes' : 'No');
        $d = fn ($v) => $v?->format('d-m-Y');

        $headings = ['Reference No.', 'Status', 'Submitted', 'Application date', 'Version', 'Advertisement', 'Post code', 'Post',
            'Salutation', 'Full name', 'Relation', "Father's / Husband's name", 'Date of birth', 'Place of birth', 'Gender', 'Marital status', 'Category',
            'PwD', 'Disability', 'Disability %', 'Ex-serviceman', 'Ex-serviceman category', 'Nationality', 'Mobile', 'Email', 'Nearest railway station',
            'Correspondence address', 'Permanent address',
            'Fee status', 'Payment method', 'UTR / DD No.', 'Payment date', 'Amount', 'Issuing bank', 'Fee exemption basis',
            'In service', 'Total experience (months)', 'Scrutiny status', 'Outcome', 'Committee status',
            'Document status', 'Endorsement status', 'Referee status'];
        for ($i = 1; $i <= $maxQ; $i++) {
            array_push($headings, "Qualification $i", "Qualification $i board/university", "Qualification $i duration", "Qualification $i year",
                "Qualification $i division", "Qualification $i %", "Qualification $i subjects", "Qualification $i specialization");
        }
        for ($i = 1; $i <= $maxE; $i++) {
            array_push($headings, "Employment $i organisation", "Employment $i post", "Employment $i type", "Employment $i from", "Employment $i to",
                "Employment $i months", "Employment $i pay scale/level", "Employment $i basic pay", "Employment $i gross pay", "Employment $i nature of duties");
        }
        foreach ($decl as $label) {
            array_push($headings, $label, "$label — details");
        }
        array_push($headings, 'Sec 21 certificates of excellence / merit', 'Sec 22 training / consultancy', 'Sec 23 professional memberships',
            'Sec 24 suitability statement', 'Sec 31 other achievements');
        for ($i = 1; $i <= $maxR; $i++) {
            array_push($headings, "Referee $i name", "Referee $i designation", "Referee $i organisation", "Referee $i address", "Referee $i email", "Referee $i phone",
                "Referee $i employer/superior", "Referee $i recommendation status");
        }
        array_push($headings, 'Declaration place', 'Declaration date', 'Declaration accepted at',
            'Endorsing organisation', 'Endorsement post', 'Endorsement capacity', 'Endorsement w.e.f.', 'Endorsement pay scale/level', 'Endorsement basic pay',
            'Next increment due', 'No disciplinary case certified', 'No objection certified', 'Forwarding officer', 'Forwarding officer designation',
            'Endorsement place', 'Endorsed on', 'Additional qualifications / employments');

        $rows = (function () use ($q, $maxQ, $maxE, $maxR, $decl, $yn, $d) {
            $relations = ['personal', 'addresses', 'post', 'advertisement', 'payment', 'qualifications', 'employments', 'declarations',
                'meritRecords', 'trainings', 'memberships', 'endorsement', 'referees.invitations', 'referees.response:id,referee_id'];
            foreach ($q->with($relations)->lazyById(300, 'applications.id', 'id') as $a) {
                $p = $a->personal;
                $e = $a->endorsement;
                $row = [$a->reference_no, $this->label($a->status), $a->submitted_at?->format('d-m-Y H:i'), $d($a->application_date), $a->version,
                    $a->advertisement->number, $a->post->code, $a->post->name,
                    $p?->salutation, $p?->full_name, $p?->relation_type ? ucfirst($p->relation_type) : null, $p?->relation_name, $d($p?->date_of_birth), $p?->place_of_birth,
                    $p?->gender, $p?->marital_status, $p?->category, $yn($p?->is_pwd), $p?->disability_type, $p?->disability_percentage,
                    $yn($p?->is_ex_serviceman), $p?->ex_serviceman_category, $p?->nationality, $p?->mobile, $p?->email, $p?->nearest_railway_station,
                    $a->address('correspondence')?->formatted(), $a->address('permanent')?->formatted(),
                    $this->label($a->fee_status), $a->payment?->method, $a->payment?->utr_number, $d($a->payment?->transaction_date), $a->payment?->amount,
                    $a->payment?->bank_name, $a->payment?->exemption_basis,
                    $yn($a->is_in_service), (int) $a->employments->sum('duration_months'),
                    $this->label($a->scrutiny_status), $this->label($a->outcome), $this->label($a->committee_status),
                    $this->label($a->document_status), $this->label($a->endorsement_status), $this->label($a->referee_status)];
                for ($i = 0; $i < $maxQ; $i++) {
                    $x = $a->qualifications->get($i);
                    array_push($row, $x?->examination, $x?->board_university, $x?->duration, $x?->year_of_passing, $x?->division, $x?->percentage, $x?->subjects, $x?->specialization);
                }
                for ($i = 0; $i < $maxE; $i++) {
                    $x = $a->employments->get($i);
                    array_push($row, $x?->organization, $x?->post_held, $x?->employment_type, $d($x?->from_date), $x?->is_current ? 'Till date' : $d($x?->to_date),
                        $x?->duration_months, $x?->pay_scale_level, $x?->basic_pay, $x?->gross_pay, $x?->nature_of_duties);
                }
                foreach (array_keys($decl) as $code) {
                    $x = $a->declarations->firstWhere('code', $code);
                    array_push($row, $yn($x?->answer), $x?->details);
                }
                array_push($row,
                    $a->meritRecords->map(fn ($m) => trim($m->title.' — '.$m->awarded_by.($m->awarded_on ? ' ('.$d($m->awarded_on).')' : '')))->implode('; '),
                    $a->trainings->map(fn ($t) => trim(ucfirst($t->type).': '.$t->title.($t->organization ? ', '.$t->organization : '')))->implode('; '),
                    $a->memberships->map(fn ($m) => trim($m->institute_name.' — '.$m->membership_category.($m->membership_number ? ' #'.$m->membership_number : '')))->implode('; '),
                    $a->suitability_statement, $a->other_achievements);
                for ($pos = 1; $pos <= $maxR; $pos++) {
                    $r = $a->referees->firstWhere('position', $pos);
                    array_push($row, $r?->name, $r?->designation, $r?->organization, $r?->address, $r?->email, $r?->phone, $yn($r?->is_employer_or_superior),
                        $r ? ($r->response ? 'Completed' : $this->label($r->invitations->first()?->recommendationStatus() ?? 'not invited')) : null);
                }
                array_push($row, $a->declaration_place, $d($a->declaration_date), $a->declaration_accepted_at?->format('d-m-Y H:i'),
                    $e?->employer_organization, $e?->designation, $e?->employment_type, $d($e?->employment_start_date), $e?->pay_scale_level, $e?->basic_pay,
                    $d($e?->next_increment_date), $yn($e?->no_disciplinary_case_certified), $yn($e?->no_objection_certified), $e?->forwarding_officer_name,
                    $e?->forwarding_officer_designation, $e?->place, $d($e?->endorsed_on));
                $overflow = $a->qualifications->slice($maxQ)->map(fn ($x) => $x->examination.' ('.$x->year_of_passing.')')
                    ->merge($a->employments->slice($maxE)->map(fn ($x) => $x->post_held.', '.$x->organization));
                $row[] = $overflow->implode('; ');
                yield $row;
            }
        })();

        return [$headings, $rows];
    }

    /** Recommendation content. Separately permissioned and always logged. */
    private function refereeContent(Builder $q): array
    {
        $ids = $q->pluck('applications.id');
        $headings = ['Application', 'Candidate', 'Post', 'Referee position', 'Recommendation ref.', 'Referee', 'Designation',
            'Organisation', 'Relationship', 'Known for', 'Overall recommendation', 'Answers', 'Comments', 'Submitted'];

        $rows = (function () use ($ids) {
            foreach (RefereeResponse::with(['application.personal', 'application.post', 'referee:id,position'])
                ->whereIn('application_id', $ids)->lazyById(300) as $r) {
                yield [$r->application->reference_no, $r->application->personal?->full_name, $r->application->post->name,
                    $r->referee->position, $r->reference_no, $r->referee_name, $r->referee_designation, $r->referee_organization,
                    $r->relationship, $r->known_duration, $r->overall_recommendation,
                    collect($r->answers)->map(fn ($x) => $x['label'].': '.(is_scalar($x['answer']) ? $x['answer'] : '—'))->implode(' | '),
                    $r->comments, $r->submitted_at?->format('d-m-Y H:i')];
            }
        })();

        return [$headings, $rows];
    }
}
