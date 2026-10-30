<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing account pool: one row per sending attempt, the health of each account, and a shared
 * counter for round-robin selection. Additive only; account credentials stay in the environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            // The account of the latest attempt (the one that sent it, once sent).
            $table->string('mail_account', 40)->nullable()->after('status')->index();
            $table->string('sender_address')->nullable()->after('mail_account');
            $table->string('smtp_response', 500)->nullable()->after('error');
            $table->string('job_id', 64)->nullable()->after('smtp_response');
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('last_attempt_at')->nullable()->after('processing_started_at');
            $table->timestamp('next_attempt_at')->nullable()->after('last_attempt_at');
        });

        Schema::create('email_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_log_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('mail_account', 40);
            $table->string('sender_address')->nullable();
            // sent | failed
            $table->string('outcome', 20);
            // connection | rate_limited | quota | domain_rate_limited | auth | config | sender_rejected
            // | recipient | message | deferred | ambiguous | other
            $table->string('failure_type', 30)->nullable();
            $table->unsignedSmallInteger('smtp_code')->nullable();
            // Server reply or error text, with credentials removed.
            $table->string('response', 500)->nullable();
            $table->string('job_id', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['mail_account', 'outcome', 'started_at']);
        });

        Schema::create('mail_account_health', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('unavailable_until')->nullable();
            $table->string('unavailable_reason', 30)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atomic_counters', function (Blueprint $table) {
            $table->string('name', 80)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
        DB::table('atomic_counters')->insert(['name' => 'mail_pool.round_robin', 'value' => 0]);
    }

    public function down(): void
    {
        Schema::dropIfExists('atomic_counters');
        Schema::dropIfExists('mail_account_health');
        Schema::dropIfExists('email_attempts');
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['mail_account']);
            $table->dropColumn(['mail_account', 'sender_address', 'smtp_response', 'job_id', 'processing_started_at', 'last_attempt_at', 'next_attempt_at']);
        });
    }
};
