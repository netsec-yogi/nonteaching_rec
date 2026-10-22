# ABV-IIITM Gwalior — Non-Teaching Recruitment and Application Scrutiny Portal

A web application that digitises the Institute's Non-Teaching Recruitment Application Form
(12.09.2025) and runs the whole recruitment workflow: advertisements and posts, online
applications, document uploads, fee details, confidential referee recommendations by email,
employer endorsement, scrutiny, the Screening Committee, reports and a complete audit trail.

| Role | What they do |
|---|---|
| **Applicant** | Register, verify email, apply for open posts through a 17-step wizard that follows the paper form's numbering, save drafts, upload documents, preview, submit, download the PDF and acknowledgment, track status, answer clarification requests. |
| **External referee** | No account. Receives a unique emailed link, fills in the confidential recommendation form, reviews, submits once. |
| **Super Administrator** | Configures everything: advertisements, posts, vacancies, eligibility, fees, document requirements, checklists, scrutiny workflow stages, referee and committee forms, email templates, settings, users, permissions, teams and committees. |
| **Recruitment Administrator** | Manages assigned advertisements: receives applications, verifies fee/documents/endorsement, assigns scrutiny, manages referee invitations, forwards to the committee, runs reports. |
| **Scrutiny User** | Verifies assigned applications item by item against a configurable checklist, records observations, requests clarifications, submits the stage report. |
| **Screening Committee Member** | Read-only access to assigned applications, scrutiny reports and recommendations; records individual evaluations or a collective decision. |

---

## Technology

| Layer | Choice |
|---|---|
| Backend | Laravel 13 (PHP 8.3+), Eloquent, Form Requests, Policies, Queues, Scheduler |
| Database | MySQL 8.0 / 8.4 |
| Frontend | Vue 3 + TypeScript, Vite, Vue Router, Pinia, Axios, Bootstrap 5 themed with the Institute design system shared with the Hostel Management app (Inter, institute navy, light/dark modes) |
| Auth | Session-cookie SPA authentication (Laravel Sanctum), role + permission RBAC |
| Queue / cache | Redis in production (database driver for development) |
| PDF | dompdf (`barryvdh/laravel-dompdf`) |
| Excel / CSV | OpenSpout (streamed) |
| Mail | Institutional SMTP; Mailpit in development |

---

## Quick start (development)

PHP and MySQL do not need to be installed: `dev.sh` runs them in Docker. Node 20+ runs natively.

```bash
./dev.sh setup     # first time: .env, composer, npm, build, migrate, seed demo data
./dev.sh start     # app http://localhost:8010 · Mailpit http://localhost:8030
```

`start` runs the application, a queue worker and the scheduler. All outgoing mail (referee
invitations included) is caught by Mailpit.

Demo accounts (password `Password@123`):

| Email | Role |
|---|---|
| superadmin@iiitm.ac.in | Super Administrator |
| recruitment@iiitm.ac.in | Recruitment Administrator (assigned to the demo advertisement) |
| scrutiny1@iiitm.ac.in, scrutiny2@iiitm.ac.in | Scrutiny Users (Team A) |
| committee1@iiitm.ac.in (chair), committee2@iiitm.ac.in | Screening Committee |
| applicant@example.com | Applicant with a submitted application (one referee has responded) |
| applicant2@example.com | Applicant with a draft in progress |

The referee links for the demo application are in Mailpit.

| Command | Does |
|---|---|
| `./dev.sh watch` | Vite dev server with hot reload (alongside `start`) |
| `./dev.sh build` | Build frontend assets |
| `./dev.sh test` | Run the PHPUnit suite against MySQL (`ntrec_test`) |
| `./dev.sh types` | Type-check the frontend (`vue-tsc`) |
| `./dev.sh fresh` | Reset the database and reseed demo data |
| `./dev.sh artisan …` / `./dev.sh composer …` | Any artisan / composer command |
| `./dev.sh logs`, `./dev.sh queue-logs` | Follow application / queue logs |
| `./dev.sh db` | MySQL shell |
| `./dev.sh stop` / `./dev.sh down` | Stop the app / stop everything |

### Without Docker

```bash
composer install
npm ci && npm run build
cp .env.example .env && php artisan key:generate   # then edit DB_*, MAIL_*, APP_URL
php artisan migrate --seed                          # schema + reference data
php artisan db:seed --class=DemoSeeder              # optional demo data
php artisan serve
php artisan queue:work --queue=mail,default         # second terminal
php artisan schedule:work                           # third terminal
```

---

## Testing

```bash
./dev.sh test                      # or: php artisan test
npm run type-check && npm run build
```

The suite (55 tests) runs against MySQL, not SQLite, because the schema relies on MySQL
behaviour. It covers the acceptance workflows end to end through the real HTTP stack:

- **Applicant**: registration and email verification, login lockout, drafting and resuming,
  every section's validation (500-word limit, distinct referee emails, conditional details,
  employment dates, Class 10 requirement), content-checked uploads, fee exemptions,
  submission (reference number, frozen snapshot, stored PDF, confirmation email), locking,
  deadline enforcement, reopening with a new version, withdrawal.
- **Referees**: exactly three independent hashed tokens; the form exposes nothing beyond
  applicant name, post and advertisement; invalid/expired/revoked links rejected; one
  submission per link; PDF linked to the right application and referee; resend, remind,
  extend, replace and revoke all logged; delivery failure tracked separately; reminders on
  schedule; optional email verification; recommendations visible to authorised staff and
  never to the applicant; immutability with audited amendments; waivers.
- **Access control**: role and assignment scoping, permissions configurable per role, staff
  cannot modify submitted applications, storage paths never exposed, append-only audit log.
- **Scrutiny and committee**: checklist verdicts, separation of duties between stages,
  return-to-previous-stage, clarification round trip without altering the application,
  fee/endorsement verification, committee evaluations and chair decision.
- **Reports**: every report builds, CSV/Excel/PDF exports, sensitive exports logged,
  spreadsheet formula injection neutralised.

---

## Documentation

| Document | For |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Developers: design, data model, workflows, security model |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Server administrators: Docker production stack (`compose.prod.yaml`, `scripts/deploy.sh`) or native install; HTTPS, queue, scheduler, restore |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Operations: environment reference, backups and recovery, retention, monitoring |
| [docs/manuals/applicant.md](docs/manuals/applicant.md) | Applicants |
| [docs/manuals/referee.md](docs/manuals/referee.md) | Referees |
| [docs/manuals/administrator.md](docs/manuals/administrator.md) | Super and Recruitment Administrators |
| [docs/manuals/scrutiny.md](docs/manuals/scrutiny.md) | Scrutiny officers |
| [docs/manuals/committee.md](docs/manuals/committee.md) | Screening Committee members |

---

## Before go-live

```bash
php artisan recruitment:preflight --production
```

checks the database, private document storage, queue driver, HTTPS, secure cookies, mail
driver, seeded templates, and that demo accounts are not active. Replace the demo data
(`migrate:fresh --seed` without `DemoSeeder`), create the real Super Administrator (see
DEPLOYMENT.md), and review the privacy notice and declaration text under **Settings** with
the Institute's approved wording. Technical controls in this system support, but do not by
themselves establish, compliance with applicable law or Institute policy.
