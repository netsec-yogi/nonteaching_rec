# Working on this codebase

ABV-IIITM Gwalior non-teaching recruitment portal. Laravel 13 API + Vue 3 SPA + MySQL 8.
Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) first.

## Running things

PHP and MySQL are not assumed to be installed locally; `./dev.sh` runs them in Docker.

```bash
./dev.sh start                      # app :8010, Mailpit :8030, queue worker, scheduler
./dev.sh test                       # PHPUnit against MySQL (ntrec_test)
./docker/php.sh php artisan …       # any php/composer/artisan command
npm run type-check && npm run build
```

## Ground rules

- **Nothing recruitment-specific is hard-coded.** If you are about to write a post name, fee,
  date, document rule or checklist item into code, it belongs in configuration data.
- **The form is data.** A wizard step is a class in `app/Services/ApplicationForm/Sections/`
  registered in `SectionRegistry`; the SPA renders steps from the payload.
- **Authorization is server-side.** New endpoint → policy/permission check + a test in
  `tests/Feature/RoleBasedAccessTest.php` or the relevant feature test.
- **Submitted data is immutable.** Never update application sections after submission
  except through `SubmissionService::reopen()`. `ApplicationVersion`, `RefereeResponse`,
  `RefereeResponseAmendment` and `AuditLog` throw on update/delete by design.
- **Audit through `AuditLogger`, statuses through `StatusService`.** Never write those
  tables directly.
- **Documents are private.** Never return `disk`/`path`; stream through `DocumentService`.
- **Referee confidentiality.** Nothing applicant-facing may include recommendation content.
  The referee endpoint must never return application data beyond name/post/advertisement.

## UI

Follow the shared ABV-IIITM design system (see ARCHITECTURE.md, "Frontend design system"):
standard Bootstrap markup (`card`, `table`, `btn-primary` / `btn-outline-secondary`,
`form-label`, `StatusBadge`), `page-title` + `page-subtitle` headings, `StatCard` for KPIs,
`table-cards` on list tables. Don't add hard-coded colours: use the CSS variables in `app.scss`
(`var(--surface)`, `var(--ink)`, `var(--danger-fg)`…) so both light and dark modes work.

## Production

`compose.prod.yaml` + `docker/prod/Dockerfile` build an immutable image (`app` target runs
PHP-FPM, the queue worker and the scheduler; `web` is Nginx with only `public/`).
`scripts/deploy.sh` is the only supported deploy path. `.env` is mounted read-only and must
be readable by gid 33; the entrypoint refuses to start otherwise.

## Traps

- `Collection` offsets: `$keyed[$id]?->x` throws on a missing key; use `->get($id)`.
- Boolean dependent rules: use `required_if_accepted` / `required_if_declined`, not
  `required_if:x,1` (JSON `true` is compared strictly).
- Queries starting from `ApplicationFilter::query()` already `select applications.*`; for
  aggregates use `->select(DB::raw(...))`, not `selectRaw()`.
- In tests, use `actingAs()` with non-stateful requests; add `fromSpa()` only for the real
  login/registration flows. Fixtures set the user with `Auth::guard('web')->setUser()`.
- dompdf: no floats inside fixed header/footer (breaks pagination); page numbers are stamped
  in `PdfService::render()`. `<col>` widths are ignored — put widths on the `<th>` cells. CSS
  line-height multiplies Noto Sans's own metrics (~1.36), so 1.1 renders as ~1.5 spacing.
  Fonts are bundled in `resources/fonts` (Noto Sans, Noto Sans Devanagari); Devanagari renders
  but without conjunct shaping.
