<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------ referees

        Schema::create('referee_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->foreignId('referee_id')->constrained()->restrictOnDelete();
            $table->string('email');
            // SHA-256 of the raw token. The raw token exists only in the email.
            $table->char('token_hash', 64)->unique();
            // APP_KEY-encrypted copy, kept only so reminders can repeat the same link.
            // Lookup never uses it; it is wiped when the invitation completes or is revoked.
            $table->text('token_encrypted')->nullable();
            // active | completed | revoked | replaced
            $table->string('state', 20)->default('active')->index();
            // not_sent | queued | sent | failed
            $table->string('delivery_status', 20)->default('not_sent')->index();
            $table->timestamp('expires_at');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivery_failed_at')->nullable();
            $table->string('delivery_error', 1000)->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->char('verification_code_hash', 64)->nullable();
            $table->timestamp('verification_code_expires_at')->nullable();
            $table->unsignedTinyInteger('verification_attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('referee_invitations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['application_id', 'referee_id', 'state']);
        });

        Schema::create('referee_responses', function (Blueprint $table) {
            // Immutable once written. Corrections go to referee_response_amendments.
            $table->id();
            $table->foreignId('referee_invitation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('referee_id')->constrained()->restrictOnDelete();
            $table->string('reference_no', 60)->unique();
            $table->string('referee_name', 200);
            $table->string('referee_designation', 200);
            $table->string('referee_organization', 250);
            $table->string('referee_email');
            $table->string('referee_phone', 20)->nullable();
            $table->string('relationship', 300);
            $table->string('known_duration', 100);
            $table->text('nature_of_association')->nullable();
            $table->json('candidate_entered'); // what the applicant typed about this referee
            $table->json('answers');           // [{code,label,type,options,answer}] — question text snapshotted
            $table->string('overall_recommendation', 100);
            $table->longText('comments')->nullable();
            $table->unsignedBigInteger('form_template_id')->nullable();
            $table->char('content_hash', 64);
            $table->string('pdf_disk', 30)->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('referee_response_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referee_response_id')->constrained()->restrictOnDelete();
            $table->string('field', 100);
            $table->text('original_value')->nullable();
            $table->text('amended_value')->nullable();
            $table->text('reason');
            $table->foreignId('amended_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // ------------------------------------------------------------ scrutiny

        Schema::create('scrutiny_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->timestamps();
        });

        Schema::create('scrutiny_team_members', function (Blueprint $table) {
            $table->foreignId('scrutiny_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['scrutiny_team_id', 'user_id']);
            $table->timestamps();
        });

        Schema::create('scrutiny_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scrutiny_stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            // assigned | in_progress | submitted | withdrawn
            $table->string('status', 20)->default('assigned')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['application_id', 'scrutiny_stage_id']);
        });

        Schema::create('scrutiny_item_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('item_label', 500); // snapshot, survives template edits
            // pending | verified | not_verified | not_applicable
            $table->string('status', 20)->default('pending');
            $table->text('remarks')->nullable();
            $table->text('discrepancy_reason')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('application_documents')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'checklist_item_id']);
        });

        Schema::create('scrutiny_reviews', function (Blueprint $table) {
            // One report per stage per application.
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scrutiny_stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // complete | incomplete | clarification_required
            $table->string('completeness', 30)->nullable();
            // further_consideration | rejection   (scrutiny recommendation)
            $table->string('recommendation', 30)->nullable();
            // recommended | incomplete | not_recommended | returned   (decision stage)
            $table->string('decision', 30)->nullable();
            $table->text('reasons')->nullable();
            $table->text('summary')->nullable();
            $table->string('status', 20)->default('draft'); // draft | submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'scrutiny_stage_id']);
        });

        Schema::create('scrutiny_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('scrutiny_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 40)->default('general');
            $table->text('observation');
            $table->boolean('is_deficiency')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('clarification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('subject', 250);
            $table->text('message');
            $table->json('checklist_item_ids')->nullable();
            $table->boolean('allow_uploads')->default(true);
            $table->timestamp('due_at');
            // open | responded | resolved | closed
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('clarification_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clarification_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
        });

        // ----------------------------------------------------------- committee

        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('evaluation_mode', 20)->nullable(); // overrides the advertisement
            $table->foreignId('evaluation_form_template_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('committee_members', function (Blueprint $table) {
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_chair')->default(false);
            $table->primary(['committee_id', 'user_id']);
            $table->timestamps();
        });

        Schema::create('committee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['committee_id', 'application_id']);
        });

        Schema::create('committee_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('responses');
            $table->string('recommendation', 40);
            $table->text('reasons')->nullable();
            $table->string('status', 20)->default('draft'); // draft | submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['committee_id', 'application_id', 'user_id'], 'committee_eval_unique');
        });

        Schema::create('committee_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 40); // recommended | not_recommended | further_review
            $table->text('reasons');
            $table->json('responses')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['committee_id', 'application_id']);
        });

        // ---------------------------------------------------------------- logs

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('template_key', 80)->index();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject', 300);
            $table->nullableMorphs('related');
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            // queued | sent | failed | delivered
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 20)->default('user'); // user | referee | system
            $table->string('actor_label', 255)->nullable();
            $table->string('action', 80)->index();
            $table->nullableMorphs('auditable');
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_type', 40); // application_document | application_pdf | referee_pdf | receipt | advertisement_document
            $table->unsignedBigInteger('resource_id');
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20); // view | download
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('report', 80);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->boolean('is_sensitive')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'export_logs', 'document_access_logs', 'audit_logs', 'notifications', 'email_logs',
            'committee_decisions', 'committee_evaluations', 'committee_assignments',
            'committee_members', 'committees', 'clarification_responses', 'clarification_requests',
            'scrutiny_observations', 'scrutiny_reviews', 'scrutiny_item_results',
            'scrutiny_assignments', 'scrutiny_team_members', 'scrutiny_teams',
            'referee_response_amendments', 'referee_responses', 'referee_invitations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
