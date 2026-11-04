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
| `MAIL_*` | Institutional SMTP (the primary sending account) | |
| `MAIL_POOL_*`, `MAIL_<NAME>_*` | Additional sending accounts and round-robin / failover across them; see `.env.example` and ARCHITECTURE.md, "Outgoing email accounts" | off |
| `RECRUITMENT_DOCUMENTS_DISK` | Private disk for uploads and PDFs | `private` |
| `CLAMAV_HOST`, `CLAMAV_PORT`, `CLAMAV_FAIL_CLOSED` | Optional virus scanning of uploads | |
| `BACKUP_PATH` | Database backup directory, outside `public/` (created `0700`, files `0600`) | `/var/backups/ntrec` |
| `BACKUP_KEEP_DAYS` | Initial backup retention (days) until it is changed under System maintenance | `30` |
| `BACKUP_ENCRYPT`, `BACKUP_ENCRYPTION_KEY`, `BACKUP_PREVIOUS_KEYS` | Encryption of backups at rest. The key is 32 bytes as `base64:…`; empty = derived from `APP_KEY`. **Keep a copy off the server**: without it the encrypted backups cannot be restored | `true`, a dedicated key |
| `BACKUP_COPY_DISK`, `BACKUP_COPY_PREFIX`, `BACKUP_COPY_PRUNE` | Optional second copy of every backup on another filesystem disk (S3-compatible, SFTP, network share) | |
| `BACKUP_DUMP_OPTIONS`, `BACKUP_RESTORE_OPTIONS` | Extra `mysqldump` / `mysql` client flags (e.g. TLS) | |
| `BACKUP_TIMEOUT` | Longest a dump or load may run (seconds) | `3600` |

Recruitment rules (fees, dates, posts, document requirements, checklists, workflow stages,
referee policy, forms, templates) are **not** environment settings: they are data edited by
the Super Administrator in the portal. System-wide defaults (institute details, privacy
notice, declaration text and version, clarification period, retention default) are under
**Settings**.

## What must be backed up

| Item | Where | How |
|---|---|---|
| Database | MySQL `ntrec` | scheduled backups (Administration → System maintenance → Database backups; default daily 01:30), or `php artisan db:backup` |
| Applicant documents and generated PDFs | `storage/app/private/` | file-level backup (rsync/restic/borg) |
| Configuration | `.env` (contains `APP_KEY` and `BACKUP_ENCRYPTION_KEY`) | secure offline copy: the backups cannot be decrypted without the key |

The database references files by path and SHA-256; restoring one without the other leaves
either missing files or orphaned files. Back up both at the same time and keep at least one
copy off the server.

### Database backup

Backups are managed under **Administration → System maintenance → Database backups**
(permission `backups.manage`; the Super Administrator holds it implicitly, no other role by
default). Every backup, whether scheduled, manual or from the command line, takes the same path:

1. `mysqldump --single-transaction` (consistent without locking): structure, data, views,
   routines, triggers, and events if the database has any. The rows of the operational tables
   (queue, cache, sessions, and the backup catalogue itself) are left out. Their structure is
   still included.
2. The SQL is gzip-compressed and encrypted (XChaCha20-Poly1305, authenticated in 1 MiB chunks)
   as it is written. It is never held in memory or written in plain text.
3. The whole file is read back: SHA-256 checksum, every chunk's authentication, complete
   decompression, and mysqldump's completion marker. Only then is the backup *completed*.
4. Optionally it is copied to `BACKUP_COPY_DISK`.

Each backup is catalogued with:
- a unique ID (`BK-YYYYMMDD-HHMMSS-XXXX`) and the file name;
- its type, size and checksum;
- who started it;
- the source server version and the schema version (its migrations).

Failed backups stay in the list with their error, and their partial files are removed.

- **Schedule**: daily, weekly or monthly at a set time, in the same screen. It runs from the
  scheduler (`backup:scheduled`, checked every minute) in its own process, so it needs no
  queue worker and nobody signed in. A slot missed while the server was down runs once the
  scheduler is back.
- **Retention**: delete backups older than N days and/or keep at most N. It is applied after
  every backup and daily (`backup:prune`). **The most recent valid backup is never deleted** by
  either rule.
- **Manual backups** from the portal run on the queue worker. The page shows their progress, and
  the requester is notified when one finishes or fails. Scheduled failures notify every holder of
  `backups.manage`.
- **Command line**: `php artisan db:backup [--prune] [--note=…]` (used by the deploy scripts
  before migrating) exits non-zero on failure. `php artisan backup:import` catalogues backup
  files that are in `BACKUP_PATH` but not listed (for example the plain `.sql.gz` files made by
  versions before this one). Each file is verified first.
- **Downloads** (permission `backups.download`, audited) give the file exactly as stored,
  encrypted. To use one elsewhere, run `php artisan backup:decrypt <ID or file> out.sql.gz` on a
  server that has the key.
- Only one backup or restore runs at a time. An operation that stops making progress is
  recorded as failed by `maintenance:sweep` (every five minutes).
- A manual backup or a restore that takes longer than the queue's `retry_after` (90 seconds by
  default) can be handed to a second worker while the first is still working. The second
  delivery does nothing, because each operation is claimed once, but it may leave an entry in
  `queue:failed`. If backups regularly take minutes, raise `REDIS_QUEUE_RETRY_AFTER`
  (`DB_QUEUE_RETRY_AFTER` for the database queue) above that time.

### Document backup (example)

```bash
# nightly, after db:backup
restic -r /mnt/backup/ntrec backup /var/www/ntrec/storage/app/private /var/backups/ntrec
restic -r /mnt/backup/ntrec forget --keep-daily 30 --keep-monthly 12 --prune
```

## Recovery

### Restore the database

**From the portal** (permission `backups.restore`, held by no role by default). Use the restore
button on a backup in **System maintenance → Database backups**. The dialog shows the checks:
- the file is present and its size and SHA-256 match;
- the encryption key is available;
- the engine and server version are compatible;
- the schema is not from a newer version of the portal;
- the mysql client is installed;
- there is enough free disk space.

It then asks for a reason, the backup ID typed back, the administrator's own password, and an
acknowledgement. The restore runs in the background:

1. The whole file is decrypted and verified again. Nothing has changed yet.
2. A **safety backup** of the current database is taken. If it fails, the restore stops.
3. The portal enters **maintenance mode** and every table except the operational ones is dropped.
   The backup is then loaded.
4. Migrations newer than the backup are applied.
5. **Health checks** run: the connection, the required tables, the schema being up to date, the
   data being readable, and an active Super Administrator existing.
6. The portal leaves maintenance mode.

If any of steps 3–5 fails, the safety backup is loaded the same way. Signed-in sessions, queued
jobs and the backup catalogue are left untouched, so the record of the restore survives. It is
listed under **Restore history** with the requester, IP, reason, checks, health results and any
rollback. Only backups the portal made can be restored: there is no upload.

**From the command line** (the same checks, safety backup and rollback):

```bash
php artisan backup:restore                      # list the backups
php artisan backup:restore BK-20261104-013000-AB12 --reason="…"
```

**If the rollback also failed**, the portal stays in maintenance mode on purpose, because the
database may be partly restored. Restore the safety backup (its ID is in the error message and
the restore history) with `php artisan backup:restore <safety ID> --reason="…"`, then run
`php artisan up`.

**By hand** (another server, or without the portal's code):

```bash
php artisan backup:decrypt BK-20261104-013000-AB12 /tmp/restore.sql.gz   # needs the backup key
php artisan down
gunzip -c /tmp/restore.sql.gz | mysql -u ntrec -p ntrec
php artisan migrate --force        # only if the code is newer than the backup
php artisan up && shred -u /tmp/restore.sql.gz
```

Restore `storage/app/private/` from the file backup taken at the same time: documents are not
in the database backup. A restore replaces the audit trail wholesale with the backup's copy,
and then adds a `backup.restored` entry. Record the restore (date, backup, reason, who authorised
it) in the Institute's change register as well, because entries written between the backup and
the restore are lost.

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

## Application logs

**Administration → System maintenance → Application logs** lists the files in `storage/logs`
(`laravel.log` with the single channel, `laravel-YYYY-MM-DD.log` with the daily channel, and
others such as `worker.log`) and the compressed archives in `storage/logs/archive`. The
permissions are separate:

| Permission | Allows |
|---|---|
| `logs.view` | the list, the overview counts, and the viewer: newest entries first, read in pages of bounded size, filtered by level, keyword, exception class, kind of problem (database connection, SQL, email/SMTP, queue, authentication, authorization, files) and time. Passwords, keys, tokens, cookies, session IDs, URL credentials, password hashes, Aadhaar and PAN numbers are masked. Viewing is audited. |
| `logs.download` | the original files, singly or as a ZIP (audited) |
| `logs.manage` | archive (gzip, verified, then the original removed), delete (with confirmation), and the retention settings |

Files a log channel is writing to (today's daily file, the single file, or anything written
in the last hour) cannot be archived or deleted. Every night `logs:maintain`:
- archives inactive files older than the set age (default 7 days);
- deletes archives past retention (default 365 days);
- alerts holders of `logs.manage` once a day when log storage passes the threshold (default 1 GB).

With the daily channel, Laravel itself deletes files older than `LOG_DAILY_DAYS` (14). Keep the
archive age below that. If logs go only to stderr (`LOG_STACK=stderr`), there are no files to
manage: use `daily` or `daily,stderr`.

## Monitoring

| Signal | Where |
|---|---|
| Failed emails (incl. referee invitations) | **Administration → Email delivery log**, status *Failed*; dashboard card |
| Sending accounts | **Email delivery log → Sending accounts**: resting / rate-limited / misconfigured accounts, daily-limit use; `php artisan mail:pool` |
| Stuck queue | Email log entries remaining *Queued* for more than a few minutes; `supervisorctl status` |
| Failed jobs | `php artisan queue:failed` (payloads are encrypted) |
| Application errors | **Administration → System maintenance → Application logs** (errors and critical errors today on its overview); `storage/logs/laravel-*.log` |
| Backups | **System maintenance** overview: latest successful backup, failures in the last 30 days, next scheduled backup; in-app alerts on failure; non-zero exit from `db:backup` |
| Health check | `GET /up` returns 200 |

Retry failed jobs after fixing the cause (for example SMTP credentials):

```bash
php artisan mail:pool --send-test=you@iiitm.ac.in   # prove each account first
php artisan mail:pool --reset                       # let resting accounts be tried at once
php artisan queue:retry all
```

An email marked *Failed* with "Delivery outcome unknown" may already have been delivered: check
with the recipient before resending it.

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
