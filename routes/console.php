<?php

use Illuminate\Support\Facades\Schedule;

// Referee reminders follow each advertisement's configured schedule.
Schedule::command('referees:send-reminders')->hourly()->withoutOverlapping();

// Database backups on the schedule set under System maintenance (default daily 01:30): checked every
// minute, run in their own process, verified, encrypted and pruned to the retention policy.
Schedule::command('backup:scheduled')->everyMinute()->runInBackground();
Schedule::command('backup:prune')->dailyAt('03:15');
// Backups and restores that stopped making progress are recorded as failed (also in maintenance mode).
Schedule::command('maintenance:sweep')->everyFiveMinutes()->evenInMaintenanceMode();
// Application logs: archive old files, apply log retention, warn on storage.
Schedule::command('logs:maintain')->dailyAt('00:20');

// Advertisements past their closing date stop showing as open; this records the state change.
Schedule::command('advertisements:close-expired')->everyFifteenMinutes();

Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();

// Candidates with unsubmitted drafts are reminded once, three days before closing.
Schedule::command('applications:deadline-reminders --days=3')->dailyAt('09:00');

// Records retention: archive and flag advertisements whose retention period has ended (deletes nothing).
Schedule::command('recruitment:retention-review')->monthlyOn(1, '02:00');
