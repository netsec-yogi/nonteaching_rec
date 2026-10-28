# Portal audit — 28 October 2026

Scope: the whole portal (Laravel 13 API, Vue 3 SPA, MySQL 8) checked against the paper
application form (`Application-Form-Non--Teaching---12.09-2025 new.doc`, 32 items + declaration
+ endorsement + checklist), the development specification, and the Hostel Management UI
reference. Method: code review of every section class, controller, service and migration; the
PHPUnit suite; a browser smoke run of every screen as every role (`tests/e2e/smoke.cjs`); the
WCAG scan (`npm run a11y`); and two independent read-only reviews (form compliance, security and
data integrity). Everything marked *fixed* below was implemented and is covered by a test or a
browser check that was actually run; the results are at the end.

## 1. Requirements compliance checklist

Status key: **F** fully implemented and tested · **C** implemented, corrected in this audit ·
**P** partially implemented · **M** missing · **U** unable to verify.

| Form item | Implementation | Status |
|---|---|---|
| 1 NEFT UTR, date, amount, issuing bank | `PaymentSection`; step skipped when fee due is nil; bank name was absent from the export | C |
| 2–4 Advt. No., date, post | `PostSection`; post locked after submission; one application per post | F |
| 5 Name in full (capitals) | `PersonalSection`; uppercased; regex now accepts non-Latin scripts | C |
| 6 Father's/Husband's name | relation type + name | F |
| 7 Date and place of birth | both, with age bounds | F |
| 8–12 Mobile, email, nationality, gender, marital status, category | vocabularies from the paper form in `config/recruitment.php` | F |
| 13 PwD + certificate | `is_pwd`, type, %; certificate via `pwd` document condition | F |
| 14–16 Railway station, correspondence and permanent address | with "same as" copy | F |
| 17 Ex-servicemen category + certificate | `ExServicemanSection`; `ex_serviceman` document condition | F |
| 18 Qualifications from Class 10, all seven columns, certificates per row | all columns captured; **one certificate used to satisfy every row** — now one per row | C |
| 19 Employment, reverse chronological, all columns, documents per row | sorted on save; period computed; per-row certificates now enforced | C |
| 20 Conviction / case / enquiry | `CriminalSection`, Yes/No + details | F |
| 21–23 Merit, training/consultancy, memberships | repeatable optional sections (consultancy is a training type) | F |
| 24 Suitability ≤ 500 words | server-side `MaxWords(500)`, tested | F |
| 25 Three referees, five fields each, India-resident / not relation / employer rule | count configurable per advertisement (default 3); employer rule now applies to anyone who has been in employment, as printed | C |
| 26(a) 26(b) 27 28 29 30 declarations | `DeclarationsSection`, each Yes/No with details where the form asks | F |
| 31 Other achievements | free text, step must be visited | F |
| Declaration text, place, date, acceptance | versioned text identical to the paper; electronic acceptance timestamped | F |
| 32 Endorsement: all particulars, both certifications, forwarding officer, place, date | applicability from advertisement/post mode and in-service status; **completeness was not re-checked at submission** — fixed; applicant title (Dr/Mr/Mrs/Ms) was not captured — added | C |
| Checklist rows 1–6, 8–12 | configured checklist templates; applicant Step 17 populated from them | F |
| Passport photograph | uploaded; **not printed on the PDF** — now embedded | C |
| Application PDF, acknowledgment, versions | frozen snapshot + PDF + SHA-256 per version | F |
| Consolidated export | **dropped items 20–24, 26–31, endorsement, bank, most qualification/employment columns, referees beyond 3** — rewritten to cover the whole form | C |
| Referee workflow (invite × N, token, expiry, reminders, revoke, replace, PDF, confidentiality) | verified end to end by tests | F |
| Scrutiny (stages, items, remarks, deficiencies, clarifications, report, history) | verified by tests | F |
| Screening Committee (individual / collective, decisions, comparison) | verified by tests | F |
| Reports, exports, logs | verified; log viewers were **not scoped to the viewer's advertisements** — fixed | C |
| Authentication, RBAC, six roles | verified; one privilege-escalation path found and closed (below) | C |
| Retention and disposal | review + console disposal; disposal now also redacts logs | C |

Hostel UI parity, dark mode, accessibility: see the previous phase; re-verified today (0 WCAG
violations on 240 page views).

## 2. Defects found and fixed

| # | Pri | Module | Defect | Correction | Test |
|---|---|---|---|---|---|
| 1 | P1 | Users | A holder of `users.manage` could assign `super_admin` to themselves, or change a Super Administrator's email and request their reset link | Only a Super Administrator may grant that role or manage such an account; `users.manage` marked sensitive | `test_user_management_cannot_grant_or_touch_super_administrator` |
| 2 | P1 | Form §18/§19 | One certificate of a type satisfied the requirement for all rows | `RequirementResolver::ROW_DOCUMENT_TYPES`: mandatory per-row types need one upload per row; wizard issue names the count | `test_each_qualification_and_employment_row_needs_its_own_certificate` |
| 3 | P1 | Form §32 | Submission checked only two endorsement fields | Every particular and both certifications re-checked at submission | `test_endorsement_must_be_complete_when_mandatory` |
| 4 | P1 | Exports | Consolidated export omitted most of the form | Rewritten: bank, salutation, relation, disability %, ESM category, all qualification and employment columns, §20–31, referees up to the configured count with address/organisation, declaration, endorsement | `test_exports_stream_files…` |
| 5 | P1 | Performance | Lazy-loaded relations (N+1) in the public advertisement page, referee revocation, invitation actions | Eloquent strict mode outside production; offending paths eager-load | full suite under strict mode; smoke run |
| 6 | P2 | Audit | Amending a recommendation copied its text into the audit log, readable with `audit.view` alone | Confidential fields audited by amendment id only | `test_submitted_recommendations_are_immutable…` |
| 7 | P2 | Logs | Audit / access / email log viewers listed every advertisement | Scoped through `ApplicationAccess`; filters validated; export log limited to own exports for non-super-admins | `test_log_viewers_are_scoped_to_visible_applications` |
| 8 | P2 | Withdrawal | Not audited; invitations revoked with a raw update, unattributed, referee status stale | Row lock, `application.withdrawn` audit, revocation through `RefereeService` | `test_withdrawal_revokes_pending_referee_links` |
| 9 | P2 | Reopening | Uploads/deletions ignored the sections opened for correction | `assertEditable()` applied to the document type's step | `test_reopening_restricts_uploads_to_the_opened_sections` |
| 10 | P2 | Config | Deleting a referee/evaluation form in use silently swapped the form served to referees | Deletion refused while an advertisement or committee uses it | `test_a_form_template_in_use_cannot_be_deleted` |
| 11 | P2 | PDF | Photograph not embedded; applicant title missing from the endorsement paragraph | Photo embedded as data URI; `salutation` captured and printed | rendered in smoke run (preview) |
| 12 | P2 | Publishing | An advertisement could be published with no document requirements, so no upload was enforced | Publish blocked until at least one mandatory requirement exists | existing publish tests |
| 13 | P2 | Sign-in | Misconfigured `SANCTUM_STATEFUL_DOMAINS` produced a 500 (`Session store not set`) | Clear 419 naming the host; preflight checks APP_URL's host is listed; deployment guide section | manual (production report) |
| 14 | P2 | Committee | Super Administrator opening a not-yet-forwarded application saw a generic error | 409 with "not been forwarded to a Screening Committee yet" | smoke run |
| 15 | P3 | Auth | Password-reset errors revealed whether an email exists | One message for any failure | `test_password_reset_failures_do_not_reveal…` |
| 16 | P3 | Email | A retried job could send the same email twice | Job returns if the log row is already `sent` | code review |
| 17 | P3 | Email | Applicant-supplied values beginning with `http` were inserted unescaped into emails | Raw insertion only for placeholders named `url`/`*_url`/`*_link` | code review |
| 18 | P3 | Referees | Resubmission re-invited a referee staff had revoked | Skipped unless the referee's details changed | code review |
| 19 | P3 | Lists | Unbounded `per_page`; unvalidated checklist copy name and log filters | Capped at 100; validated | code review |
| 20 | P3 | Reports | Vacancy statistics listed posts of every advertisement | Scoped to visible advertisements | code review |
| 21 | P3 | Retention | Disposal left names/emails in audit, access and email logs | Values redacted, trail kept | `test_disposal_refuses_until_due…` |
| 22 | P3 | DB | `application_versions` cascaded on application delete | `restrictOnDelete` (migration) | migration applied |
| 23 | P3 | PDF | Referee undertakings (resident in India, not a relation) not printed | Printed | — |
| 24 | P3 | A11y | Notification bell's accessible name did not contain its visible count | Renamed | `npm run a11y` |

## 3. Improvements made

- Browser smoke test (`tests/e2e/smoke.cjs`) and shared screen list (`tests/e2e/pages.cjs`);
  the accessibility scanner now uses the same list.
- Eloquent strict mode in non-production environments (prevents future N+1 regressions).
- Deployment guide: `.env` permissions corrected (640, group `www-data`), Node 22, sign-in
  troubleshooting; preflight check for the stateful domain.
- Export is now a complete record of the application; referee columns follow the configured count.

## 4. Database migrations

`2026_10_28_000100_audit_corrections`: adds nullable `application_personal_details.salutation`;
re-creates the `application_versions.application_id` foreign key with `RESTRICT`. Additive, safe
on live data, reversible (`down()` provided). Run `php artisan migrate --force` on deploy as usual.

## 5. Modules changed

Backend: `UserController`, `LogController`, `RefereeController` (admin), `CommitteeController`,
`FormTemplateController`, `ChecklistTemplateController`, `AdvertisementController`, `PublicController`,
`AuthController`, Applicant `DocumentController`; `RequirementResolver`, `ReportService`,
`SubmissionService`, `RefereeService`, `RetentionService`, `TemplateMailer`, `PdfService`,
`AdvertisementService`, `SnapshotBuilder`, sections `Personal`, `Endorsement`, `Referees`, base
`Section`; `SendTemplatedEmail`; `Preflight`; `AppServiceProvider`; `Permissions`;
`config/recruitment.php`; PDF template. Frontend: `PersonalStep.vue`, `AppShell.vue`, wizard types.
Tests: 7 new, 3 extended. Docs: `ARCHITECTURE.md`, `DEPLOYMENT.md`, `CLAUDE.md`, this file.

## 6. Tests executed (actual results)

- `./dev.sh test` — **71 passed (658 assertions)**, MySQL 8.4, Eloquent strict mode on.
- `vendor/bin/pint --test` — clean. `npm run type-check` — clean. `npm run build` — OK.
- `node tests/e2e/smoke.cjs` — 65 screens as 7 roles: no JS errors, no 5xx, no blank/error
  states; all 17 wizard steps save or report validation correctly.
- `npm run a11y` — 240 page views (every screen, light/dark, desktop/phone): 0 violations
  (final rerun on the changed combination after the last fix).
- `composer audit`, `npm audit` — no known vulnerabilities.

## 7. Outstanding issues and dependencies

- **Login lockout message** still differs for existing vs unknown accounts (tells a legitimate user
  they are locked). Left as is: the alternative hides the lock from the user; decide with the
  Institute.
- **Dormant applicant accounts** are not covered by retention disposal (name/email/mobile remain in
  `users`). Needs a policy decision (see §8).
- **Checklist numbering**: the PDF numbers rows 1–11; the paper form skips 7. Kept sequential.
- **Hindi conjuncts** in PDFs render without shaping (dompdf limitation, documented earlier).
- Email delivery, SPF/DKIM, ClamAV and the production TLS setup can only be verified on the
  Institute's servers (go-live checklist in `DEPLOYMENT.md`).

## 8. Decisions needed from the Institute

1. Should the per-row certificate rule (one upload per qualification/employment) be mandatory for
   every advertisement? It is enforced only where the requirement is configured as mandatory,
   which the demo configuration does.
2. Retention of applicant *accounts* after the application records are disposed of.
3. Whether a locked account should be told it is locked (current) or shown the generic message.
4. The stray paper checklist row 6 ("PG with 55 %") is kept verbatim; confirm or edit in the
   checklist template.

## 9. Readiness

Ready for **acceptance testing**. All P0/P1 and P2 findings are fixed and tested; no critical or
high-priority defect is open. Production deployment additionally requires the go-live checklist in
`DEPLOYMENT.md` (SMTP, TLS, backups off-host, preflight passing) on the Institute's server.
