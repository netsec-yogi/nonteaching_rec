<?php

use Illuminate\Support\Facades\Schedule;

// Referee reminders follow each advertisement's configured schedule.
Schedule::command('referees:send-reminders')->hourly()->withoutOverlapping();

// Nightly database backup, verified and pruned to BACKUP_KEEP_DAYS.
Schedule::command('db:backup --prune')->dailyAt('01:30')->withoutOverlapping();

// Advertisements past their closing date stop showing as open; this records the state change.
Schedule::command('advertisements:close-expired')->everyFifteenMinutes();

Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Candidates with unsubmitted drafts are reminded once, three days before closing.
Schedule::command('applications:deadline-reminders --days=3')->dailyAt('09:00');

// Records retention: archive and flag advertisements whose retention period has ended (deletes nothing).
Schedule::command('recruitment:retention-review')->monthlyOn(1, '02:00');
