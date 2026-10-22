# Operations

## Environment reference

| Variable | Purpose | Production value |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | Environment, error detail | `production`, `false` |
| `APP_KEY` | Encrypts sessions, queued email payloads and the referee resend copy of tokens. **Back it up**; losing it makes pending referee links impossible to resend (replacements still work). Never rotate casually. | generated |
| `APP_URL`, `FRONTEND_URL` | Base URL; emailed links use `FRONTEND_URL` | `https://…` |
| `APP_TIMEZONE` | Timezone for stored timestamps and deadlines | `Asia/Kolkata` |
| `FORCE_HTTPS`, `TRUSTED_PROXIES` | HTTPS URLs behind a TLS proxy | `true`, proxy IP |
| `DB_*` | MySQL connection | |
| `SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `SESSION_LIFETIME` | Cookie security, idle timeout (minutes) | `true`, `true`, `60` |
| `SANCTUM_STATEFUL_DOMAINS` | Host(s) serving the SPA | `recruitment.iiitm.ac.in` |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `REDIS_*` | Queue and cache | `redis` |
| `MAIL_*` | Institutional SMTP | |
| `RECRUITMENT_DOCUMENTS_DISK` | Private disk for uploads and PDFs | `private` |
| `CLAMAV_HOST`, `CLAMAV_PORT`, `CLAMAV_FAIL_CLOSED` | Optional virus scanning of uploads | |
| `BACKUP_PATH`, `BACKUP_KEEP_DAYS` | Database backup directory and retention | |

Recruitment rules (fees, dates, posts, document requirements, checklists, workflow stages,
referee policy, forms, templates) are **not** environment settings: they are data edited by
the Super Administrator in the portal. System-wide defaults (institute details, privacy
notice, declaration text and version, clarification period, retention default) are under
**Settings**.

## What must be backed up

| Item | Where | How |
|---|---|---|
| Database | MySQL `ntrec` | `php artisan db:backup` (nightly via the scheduler) |
| Applicant documents and generated PDFs | `storage/app/private/` | file-level backup (rsync/restic/borg) |
| Configuration | `.env` (contains `APP_KEY`) | secure offline copy |

The database references files by path and SHA-256; restoring one without the other leaves
either missing files or orphaned files. Back up both at the same time and keep at least one
copy off the server.

### Database backup

`php artisan db:backup` runs `mysqldump --single-transaction` (consistent without locking),
compresses it, then verifies the archive decompresses and ends with mysqldump's completion
marker. Files are written to `BACKUP_PATH` with mode `0600`; `--prune` deletes backups older
than `BACKUP_KEEP_DAYS`. A failed or unverified backup exits non-zero — wire the cron output
or `storage/logs` into monitoring.

### Document backup (example)

```bash
# nightly, after db:backup
restic -r /mnt/backup/ntrec backup /var/www/ntrec/storage/app/private /var/backups/ntrec
restic -r /mnt/backup/ntrec forget --keep-daily 30 --keep-monthly 12 --prune
```

## Recovery

### Restore the database

```bash
php artisan down
gunzip -c /var/backups/ntrec/ntrec-YYYYMMDD-HHMMSS.sql.gz | mysql -u ntrec -p ntrec
php artisan migrate --force        # only if the code is newer than the backup
php artisan up
```

Restore `storage/app/private/` from the file backup taken at the same time. A restore
replaces the audit trail wholesale with the backup's copy: record the restore (date, backup
file, reason, who authorised it) in the Institute's change register, because entries written
between the backup and the restore are lost.

### Verify after a restore

1. `php artisan recruitment:preflight`.
2. Open a submitted application and download its PDF and one uploaded document.
3. Check the email log for messages that were queued but not sent before the incident;
   resend referee invitations from the application's referee panel where needed.

### Rehearse

Restore the latest backup to a separate staging database at least once per recruitment
cycle and run the verification above. An untested backup is not a backup.

## Data retention and archival

- Each advertisement has a **retention period (years)**, counted from its closing date. Left
  blank, it uses **Settings → retention.default_years**.
- **Monthly review (automatic, deletes nothing).** `recruitment:retention-review` runs on the
  1st of each month. Closed advertisements whose retention period has ended are archived
  (read-only) and flagged as due for disposal, and Super Administrators are emailed the list.
- **Disposal (deliberate, irreversible).** Once the Institute has authorised disposal under
  its records policy, take a backup, then run:

  ```bash
  php artisan recruitment:dispose "IIITM/NT/2026/03" \
      --confirm="IIITM/NT/2026/03" \
      --authorised-by="Name, Designation (order no. and date)" \
      --operator=registrar@iiitm.ac.in
  ```

  It refuses unless the advertisement is archived and flagged as due. It deletes every
  stored file of the advertisement's applications (uploads and generated PDFs), deletes
  qualifications, employment, addresses and other repeatable records, and redacts personal
  details, referee details, recommendation content, frozen snapshots and email recipients.
  It **keeps** reference numbers, statuses and outcomes, scrutiny and committee records,
  status history, payment amounts and statuses, category and gender (for statistics), and the
  audit trail, so the recruitment outcome remains provable. Each disposal is written to the
  audit log and to `storage/logs/retention.log`. Applicant login accounts are not deleted.
- Archiving an advertisement makes it read-only; until disposal, applications and documents
  remain available to authorised staff.
- Unsubmitted drafts may be discarded by the candidate at any time.

## Monitoring

| Signal | Where |
|---|---|
| Failed emails (incl. referee invitations) | **Administration → Email delivery log**, status *Failed*; dashboard card |
| Stuck queue | Email log entries remaining *Queued* for more than a few minutes; `supervisorctl status` |
| Failed jobs | `php artisan queue:failed` (payloads are encrypted) |
| Application errors | `storage/logs/laravel-*.log` |
| Backups | non-zero exit / log lines from `db:backup` |
| Health check | `GET /up` returns 200 |

Retry failed jobs after fixing the cause (for example SMTP credentials):

```bash
php artisan queue:retry all
```

## Security operations

- Accounts lock for 15 minutes after 5 failed sign-ins; administrators can also deactivate
  a user, which ends their sessions immediately.
- Staff accounts never receive a password from an administrator: they set it through an
  emailed reset link.
- Document views and downloads, recommendation views, and every export are logged
  (**Administration → Audit trail** and the access/export logs).
- Referee links are 64-character random tokens; only their SHA-256 is used for lookup. An
  APP_KEY-encrypted copy is kept solely to repeat the same link in reminders and is erased
  when the invitation completes, is revoked or is replaced.
- Review **Roles & permissions** at the start of each recruitment cycle.
