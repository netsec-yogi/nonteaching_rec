# Architecture

Laravel 13 JSON API + Vue 3 single-page application, one deployable unit. The SPA is served
by `routes/web.php` (`resources/views/app.blade.php`); everything else is `/api/*`
(`routes/api.php`) using Sanctum session-cookie authentication.

```
app/
  Http/Controllers/        thin: authorize → validate → delegate → respond
    Applicant/             wizard, documents, clarification responses
    Admin/                 staff console (applications, scrutiny, committee, configuration, reports, logs)
    RefereeController      token-only referee form
  Http/Requests/           Form Requests for registration, advertisements, posts
  Http/Middleware/         role, permission, active-user, security headers
  Policies/ApplicationPolicy.php   every application-level ability
  Services/                all domain rules (see below)
  Services/ApplicationForm/Sections/   one class per wizard step
  Models/                  58 Eloquent models
  Jobs/SendTemplatedEmail  queued, encrypted email delivery
  Console/Commands/        reminders, backups, preflight, admin bootstrap
resources/js/
  api/ stores/ router/ layouts/ components/ composables/ utils/
  views/public|auth|applicant|referee|admin/…
```

## Frontend design system

The UI shares the design system of the Institute's Hostel Management application
(`hostel_mgmt`), adapted to this portal's Bootstrap 5 stack rather than adding a second CSS
framework. `resources/scss/app.scss` holds the hostel tokens (institute navy brand ramp,
saffron accent, semantic surface/ink/line greys, Inter, card radius and shadows) as Bootstrap
variables and CSS custom properties, and restyles Bootstrap components to the hostel rules:
cards, uppercase-header data tables, buttons, labels, ringed status pills, underline tabs,
pagination, dialogs, toasts. Layouts mirror the hostel ones: `AppShell` (white sidebar with
deep-navy brand strip, sticky top bar with title, notifications and user menu; used by staff
and signed-in applicants), `GuestLayout` (split sign-in screen), the public top bar, and the
referee `BareLayout`. Shared pieces: `StatCard` (KPI tile), `Toasts`, `Pager`,
`LoadingState`, `BaseModal`, `StatusBadge`. List tables marked `table-cards` reflow into
labelled cards on phones (`utils/tableCards.ts` labels cells from their headers).
Light and dark colour modes use Bootstrap's `data-bs-theme` with the hostel's dark palette:
custom rules read semantic CSS variables (`--surface`, `--ink`, `--line`, `--brand-solid`,
`--success-bg`…) defined for both modes at the top of `app.scss`. The top-bar sun/moon toggle
(`components/ThemeToggle.vue`, `utils/theme.ts`) stores the choice per browser; light is the
default, as in the hostel app. `public/theme-init.js` applies it before first paint (an
external file, because the production CSP forbids inline scripts). Icons are
Bootstrap Icons (outline set), kept instead of adding Lucide alongside them.

## Ground rules

1. **Nothing recruitment-specific is hard-coded.** Advertisements, posts, vacancies,
   eligibility criteria, fees and exemptions, payment methods, document requirements and
   their conditions, checklist templates, scrutiny stages, referee questions and rating
   scales, committee forms, email templates and permissions are rows edited in the portal.
   Vocabularies printed on the paper form itself (gender, marital status, category) live in
   `config/recruitment.php`.
2. **Domain rules live in `app/Services`.** Controllers authorize and delegate.
3. **Authorization is server-side.** `ApplicationAccess` decides *which* applications a user
   can see (assigned advertisements, scrutiny teams/assignments, committees, own
   applications); `ApplicationPolicy` combines that with role permissions for each ability.
   The SPA hides buttons for convenience only.
4. **Submitted data is immutable.** Submission freezes an `ApplicationVersion` (snapshot +
   PDF + SHA-256). Corrections happen only through an audited reopening that produces a new
   version. Scrutiny, clarifications and committee work are separate records and never
   write to the candidate's data. Referee responses, versions, amendments and audit entries
   refuse updates/deletes at the model level.
5. **Documents are private.** Files live on a non-served disk; responses never include
   `disk`/`path`; every read goes through `DocumentService` after a policy check and is logged.

## The application form as data

`SectionRegistry` lists 17 `Section` classes in paper-form order. Each provides
`rules()` (validation), `save()`, `data()` (what the wizard renders and posts back) and
`issues()` (plain-language reasons the step blocks submission, including missing mandatory
documents whose document type is configured for that step).

| Step | Paper form | Class |
|---|---|---|
| payment | 1 | PaymentSection |
| post | 2–4 | PostSection |
| personal | 5–16 | PersonalSection |
| ex_serviceman | 17 | ExServicemanSection |
| qualifications | 18 | QualificationsSection |
| employment | 19 | EmploymentSection |
| criminal | 20 | CriminalSection |
| merit / training / memberships | 21 / 22 / 23 | RepeatableOptionalSection subclasses |
| suitability | 24 | SuitabilitySection (server-side 500-word limit) |
| referees | 25 | RefereesSection |
| declarations | 26(a), 26(b), 27–30 | DeclarationsSection |
| achievements | 31 | AchievementsSection |
| declaration | Declaration | CandidateDeclarationSection (versioned text) |
| endorsement | 32 | EndorsementSection (applicability from the advertisement/post mode) |
| checklist | Check List | ChecklistSection (items from assigned checklist templates) |

`ApplicationFormService::payload()` returns everything the wizard needs in one response;
`SnapshotBuilder` renders the human-readable form used for the frozen version, the PDF, the
preview and every staff view — so they always agree.

`RequirementResolver` turns configured `document_requirements` (condition: always,
reserved_category, pwd, ex_serviceman, in_service, has_employment, criminal_disclosure,
fee_payable) and `checklist_assignments` (advertisement-wide plus post-specific templates)
into the concrete list for one application. `FeeCalculator` applies the fee schedule
(`{"default": 500, "exemptions": {"SC": 0, …}}`, post overrides advertisement; lowest
applicable amount wins).

## Submission

`SubmissionService::submit()` in one transaction, with the application and advertisement
rows locked: re-checks every section's issues, the deadline (unless reopened) and email
verification; assigns `{prefix}/{sequence}` from the advertisement's counter; sets statuses
(scrutiny `submitted`, fee `pending_verification`/`exempt`, endorsement); freezes documents;
writes the version snapshot; creates referee invitations. After commit it renders the PDF,
emails the confirmation with the PDF attached and notifies staff.

Status fields on `applications` are independent axes, each with history in
`application_status_histories` (written only by `StatusService`): `status` (draft/submitted/
reopened/withdrawn), `scrutiny_status`, `outcome`, `committee_status`, `fee_status`,
`endorsement_status`, `referee_status`, `document_status`.

## Referee workflow

`RefereeService`:

- **Issue**: 32 random bytes → 64-hex token. `token_hash` (SHA-256, unique) is the only
  lookup key. `token_encrypted` (APP_KEY) exists only so reminders and resends repeat the
  same link; it is cleared on completion/revocation/replacement. Expiry =
  advertisement's `referee_deadline_days`, end of day.
- **Email**: `TemplateMailer` → `email_logs` row (queued) → `SendTemplatedEmail` job
  (`ShouldBeEncrypted`, 5 tries with back-off) → `sent` / `failed`. Invitation
  `delivery_status` (not_sent/queued/sent/failed) is separate from `state`
  (active/completed/revoked/replaced) and from expiry; the staff table shows them separately.
- **Access**: `resolve()` validates format, hash, invitation↔referee↔application linkage,
  state and expiry. The public view returns only institute details, advertisement number,
  post, applicant name, the referee's own prefill and the configured form. Optional
  per-advertisement email verification (6-digit code, hashed, 5 attempts, 15 min).
  Endpoints are rate limited.
- **Submit**: re-locks the invitation, refuses if not active/expired/already answered
  (unique `referee_invitation_id`), snapshots question text with each answer, keeps the
  candidate-entered referee details beside any corrections, computes a content hash, marks
  completed, recomputes `referee_status`; after commit generates the PDF and notifies staff.
- **Manage**: resend, remind, extend, revoke, replace (new invitation, same referee and
  application, `replaces_id` chain), invite; waive the requirement; grant duplicate-email
  exception; record amendments (append-only). All audited.
- **Reminders**: hourly; per advertisement `referee_reminder_days` after invitation plus a
  final reminder `referee_final_reminder_days_before` expiry, at most
  `referee_max_reminders`, never more than one per day.
- **Never** shown to the applicant: their status page shows only received / awaiting.

## Scrutiny and committee

Each advertisement has ordered `scrutiny_stages` (role, decision stage, `allow_same_reviewer`).
Default: Initial scrutiny (scrutiny user) → Review by authorised officer (recruitment admin,
decision stage). `ScrutinyService`:

- assignment (role check; a user who reported at an earlier stage cannot be assigned to or
  submit a later stage unless that stage allows it);
- per-item verdicts (verified / not verified / not applicable / pending) with remarks,
  discrepancy reason (required where configured) and the document checked, label
  snapshotted;
- observations and deficiencies; clarification requests and candidate responses (response
  documents attached to the response, frozen, never altering the application);
- stage report (completeness + recommendation; mandatory items must not be pending) and the
  decision stage (recommended / incomplete / not recommended / returned to previous stage);
- advisory eligibility checks from post criteria (never automatic rejection);
- forwarding to a committee (requires a scrutiny recommendation and, if configured, complete
  referee recommendations; Super Administrator override).

`CommitteeService`: individual evaluations (draft → submitted, locked) or collective mode;
decisions by the chair or a user with `committee.record_decision`; revisions audited;
comparison report.

## Notifications and email templates

`email_templates` rows (Markdown with `{{ placeholders }}`, values escaped) for verification,
password reset, submission, acknowledgment, reopening, referee invitation / reminder /
verification code / completion / delivery failure, clarification request / receipt, scrutiny
assignment / completion, decision, deadline reminder. In-app notices use Laravel database
notifications (`StaffAlert`, `ApplicantAlert`).

## Reports

`ReportService` defines 20 reports plus the consolidated export (one row per application,
repeatable sections flattened, three referee column groups) and the separately-permissioned
referee-content export. One row generator feeds the screen preview, CSV/XLSX (streamed,
formula-injection safe) and PDF. Every export is written to `export_logs`; sensitive ones also
to the audit log.

## Security summary

Session cookies (HttpOnly, Secure, SameSite=Lax, encrypted), CSRF via Sanctum, login rate
limiting + account lockout, password policy, session invalidation on password reset and
deactivation, least-privilege permissions, policies on every record, upload checks (size,
detected MIME, magic bytes, active-PDF refusal, optional ClamAV), private storage, security
headers and CSP, `Referrer-Policy: same-origin` (no Referer to other sites; Sanctum needs it on same-origin requests), encrypted queue payloads, append-only audit
log, document/recommendation access logs.
