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
deep-navy brand strip, sticky top bar with title, notifications and user menu; used by staff),
`ApplicantShell` (signed-in applicants: no sidebar, one sticky header with branding, the main
links, a "My applications" dropdown, notifications and the account menu; the links collapse
behind a menu button below `xl`), `GuestLayout` (split sign-in screen), the public top bar, and
the referee `BareLayout`. The bell and account menu are shared components
(`components/shell/NotificationBell.vue`, `UserMenu.vue`). Shared pieces: `StatCard` (KPI tile), `Toasts`, `Pager`,
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

`SectionRegistry` lists 17 `Section` classes in paper-form order, except that personal details
come first and the fee step second (the fee depends on category, gender, PwBD and ex-serviceman
details). The fee step is left out of the wizard, and refuses saves and fee-proof uploads, when
the fee calculated for the applicant is zero (`Section::rejectsWhenNotApplicable`). Each provides
`rules()` (validation), `save()`, `data()` (what the wizard renders and posts back) and
`issues()` (plain-language reasons the step blocks submission, including missing mandatory
documents whose document type is configured for that step).

| Step | Paper form | Class |
|---|---|---|
| personal | 5–16 | PersonalSection |
| payment | 1 | PaymentSection (only when a fee is payable) |
| post | 2–4 | PostSection |
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
applicable amount wins). After every saved step, and at submission, `PaymentSection::syncFeeRecord`
keeps the payment row in line with the fee calculated now: a zero fee is an `exempt` row (fee
status `exempt` with its basis, or `not_required`), never unpaid; an exemption is removed once a
fee applies again; a verified payment is never rewritten; replacements are audited.

Whether a required document must be uploaded: `document_types.is_mandatory` is the default, and
an advertisement's requirement may override it (`document_requirements.is_mandatory`: true / false,
or null to follow the type) — `DocumentRequirement::isMandatory()`. Requirements apply only when
their condition holds for the candidate, so a mandatory type is never demanded where it does not
apply. Missing mandatory documents block submission; missing optional ones never do. The scrutiny
workspace lists the applicable requirements (`ScrutinyService::documentChecklist`); a missing
mandatory document is reported as a deficiency and keeps `document_status` deficient.

## Application fee: bank details, QR code and verification

The fee *amount* and exemptions live only in the advertisement / post `fee_schedule`;
`FeeCalculator` computes the amount due on the server (the browser's figure is never trusted).
Where to pay is configured under Administration → Application fee settings
(`payments.configure`, also limited to advertisements the user manages):

- `fee_payment_configs` — bank details (account number encrypted at rest), UPI ID, instructions,
  payment deadline and an optional bank QR image (private disk, streamed through
  `FeePaymentConfigService::streamQr`, stored exactly as uploaded after type/signature/size/
  dimension checks). Scope is an advertisement (`post_id` null) or one post, fixed after creation;
  "copy" makes an independent inactive copy for another scope. One active configuration per
  scope; a post-specific one overrides the advertisement-wide one.
- Every change bumps `version` and writes an append-only `fee_payment_config_revisions` row; the
  audit trail records old/new values with the account number masked. Editing, activating a
  replacement for, or deactivating a configuration that live applications use needs explicit
  confirmation. An advertisement with a fee cannot be published until each fee-paying post has
  an active configuration.
- When the candidate saves the payment step, `payments` stores the configuration id, version and
  an encrypted snapshot of the details shown, so later edits never change what a payment was
  made against. The wizard shows the current configuration for drafts and the snapshot once
  submitted (`FeePaymentConfigService::panelFor`); the QR is served to the owner only.
- Manual payments are `pending_verification` on submission. `/admin/payments` (permission
  `applications.verify`, scoped by `ApplicationAccess`) lists them with display statuses
  (pending payment, details submitted, pending verification, verified, rejected, exempted, not
  required) and amount mismatches; verify/reject (reason required) is recorded in
  `payment_verifications`, the status history and the audit trail.

## Signature, PAN / Aadhaar and signed undertakings

- The candidate's signature is a document type (`signature`, Personal step, images only) going
  through `DocumentService` like any upload; dimension limits come from
  `recruitment.image_dimensions`, the size limit from the document type. Optional unless an
  advertisement adds a requirement for it. The PDF prints it beside the declaration, scaled to fit
  a 50 × 18 mm box without distortion.
- PAN and Aadhaar numbers (`application_personal_details`, encrypted casts, hidden from
  serialisation) are optional unless the advertisement's `identity_number_requirement` asks for one.
  `App\Support\IdentityNumbers` normalises and format-checks them (Aadhaar: 12 digits with a
  Verhoeff check digit — format only, never UIDAI verification) and masks them. Snapshots, PDFs and
  staff views carry only masked values; the full numbers are served by
  `GET admin/applications/{uuid}/identity` to holders of `identity.view`, and each view is logged
  in `document_access_logs` and the audit trail. They are redacted from audit values and wiped on
  retention disposal.
- A signed undertaking ("I shall produce the required/missing document…") may be uploaded in place
  of a document where the requirement accepts it (`document_requirements.accepts_undertaking`,
  null = the document type's `accepts_undertaking`). It is stored as an `application_documents` row
  of that document type with `upload_type = undertaking` and `original_status = awaited`; it counts
  towards a mandatory requirement only where accepted. Verifying it accepts the undertaking, not the
  original. Staff record the original later (`POST …/documents/{uuid}/original`:
  received_verified / received_rejected / awaited), keeping the undertaking and its decision.
  `document_status` becomes `originals_awaited` while accepted undertakings still await originals.

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
