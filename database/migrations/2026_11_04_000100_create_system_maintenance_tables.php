<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System maintenance: the catalogue of database backups and the history of restores.
 *
 * Both are operational tables: backups leave their rows out and a restore leaves them untouched,
 * so the catalogue and the record of every restore survive restoring an older backup. For the
 * same reason they hold no foreign keys to users (a restore replaces the users table); the
 * initiating administrator is kept as an id plus a name snapshot.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'backups.manage' => 'View and create database backups, delete old ones and set the backup schedule and retention',
        'backups.download' => 'Download database backups (a complete copy of all portal data)',
        'backups.restore' => 'Restore the database from a backup (replaces all current data)',
        'logs.view' => 'View application (Laravel) logs, with secrets masked',
        'logs.download' => 'Download original application log files',
        'logs.manage' => 'Archive and delete application log files and set log retention',
    ];

    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('identifier', 40)->unique();
            $table->string('type', 20)->index();          // manual, scheduled, safety, command, imported
            $table->string('status', 20)->index();        // queued, running, completed, failed
            $table->string('filename', 190)->nullable()->unique();
            $table->boolean('encrypted')->default(false);
            $table->string('key_id', 16)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('dump_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->string('note', 255)->nullable();
            $table->dateTime('schedule_slot')->nullable()->unique();
            $table->unsignedBigInteger('initiated_by')->nullable()->index();
            $table->string('initiated_by_name', 150)->nullable();
            $table->unsignedBigInteger('restore_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->string('copy_status', 20)->nullable();
            $table->dateTime('copied_at')->nullable();
            $table->string('copy_error', 500)->nullable();
            $table->dateTime('deleted_at')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_by_name', 150)->nullable();
            $table->string('deletion_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'completed_at']);
        });

        Schema::create('backup_restores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('database_backup_id')->constrained('database_backups')->restrictOnDelete();
            $table->foreignId('safety_backup_id')->nullable()->constrained('database_backups')->nullOnDelete();
            $table->string('status', 20)->index();        // queued, running, completed, failed
            $table->string('stage', 30)->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('requested_by_name', 150)->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->json('checks')->nullable();
            $table->json('health')->nullable();
            $table->json('migrations_applied')->nullable();
            $table->string('rollback_status', 20)->nullable();
            $table->boolean('maintenance_left_on')->default(false);
            $table->text('error')->nullable();
            $table->dateTime('queued_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('heartbeat_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        // Grantable from Roles & permissions without re-running the reference seeder. None is
        // given to any role by default: the Super Administrator holds them implicitly.
        if (Schema::hasTable('permissions')) {
            $now = now();
            DB::table('permissions')->insertOrIgnore(collect(self::PERMISSIONS)->map(fn ($name, $slug) => [
                'slug' => $slug, 'name' => $name, 'group' => 'maintenance', 'is_sensitive' => true, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('database_backups');
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();
        }
    }
};
