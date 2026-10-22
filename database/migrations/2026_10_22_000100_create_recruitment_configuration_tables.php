<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything an administrator configures before applications arrive:
 * cycles, advertisements, posts, vacancies, eligibility, documents,
 * checklists, workflow stages, referee forms, committee forms, templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('recruitment_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('year', 20);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 150);
            $table->string('description')->nullable();
            $table->json('allowed_mimes');
            $table->unsignedInteger('max_kb')->default(2048);
            $table->string('step', 40)->nullable(); // wizard step that shows the upload slot
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('referee_form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('introduction')->nullable();
            $table->json('recommendation_options');
            $table->text('good_faith_statement');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('referee_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referee_form_template_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 500);
            $table->string('help_text', 500)->nullable();
            $table->string('type', 20); // yes_no, rating, choice, short_text, long_text
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['referee_form_template_id', 'code']);
        });

        Schema::create('evaluation_form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->json('recommendation_options');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('evaluation_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_form_template_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 300);
            $table->string('help_text', 500)->nullable();
            $table->string('type', 20); // yes_no, rating, choice, short_text, long_text
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['evaluation_form_template_id', 'code']);
        });

        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_cycle_id')->constrained()->restrictOnDelete();
            $table->string('number', 60)->unique();
            $table->string('title', 300);
            $table->date('publication_date')->nullable();
            $table->string('department', 200)->nullable();
            $table->text('summary')->nullable();
            $table->longText('instructions')->nullable();
            $table->longText('reservation_details')->nullable();
            $table->longText('age_relaxation_rules')->nullable();
            $table->longText('fee_rules_text')->nullable();
            $table->longText('payment_instructions')->nullable();
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->string('status', 20)->default('draft')->index();
            $table->string('reference_prefix', 30);
            $table->unsignedInteger('reference_sequence')->default(0);
            // Fee: {"default": 500, "exemptions": {"SC": 0, "ST": 0, "PWD": 0, "FEMALE": 0, "ESM": 0}}
            $table->json('fee_schedule')->nullable();
            $table->json('payment_methods');
            // Referee policy
            $table->unsignedTinyInteger('referees_required')->default(3);
            $table->boolean('referee_recommendations_mandatory')->default(false);
            $table->unsignedSmallInteger('referee_deadline_days')->default(14);
            $table->json('referee_reminder_days')->nullable(); // days after invitation
            $table->unsignedSmallInteger('referee_final_reminder_days_before')->nullable();
            $table->unsignedTinyInteger('referee_max_reminders')->default(3);
            $table->boolean('referee_extra_verification')->default(false);
            $table->foreignId('referee_form_template_id')->nullable()->constrained()->nullOnDelete();
            // Employer endorsement: not_applicable | optional | required_for_in_service
            $table->string('endorsement_mode', 30)->default('required_for_in_service');
            $table->longText('endorsement_instructions')->nullable();
            // Committee and corrections
            $table->string('committee_evaluation_mode', 20)->default('individual'); // individual | collective
            $table->foreignId('evaluation_form_template_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('allow_withdrawal')->default(true);
            $table->boolean('allow_clarification_uploads')->default(true);
            $table->unsignedSmallInteger('retention_years')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('advertisement_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('original_name');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->boolean('is_public')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('advertisement_user', function (Blueprint $table) {
            // Recruitment administrators assigned to manage an advertisement.
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['advertisement_id', 'user_id']);
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 200);
            $table->string('department', 200)->nullable();
            $table->string('group_classification', 30)->nullable();
            $table->string('pay_level', 50)->nullable();
            $table->string('pay_scale', 200)->nullable();
            $table->unsignedSmallInteger('total_vacancies')->default(0);
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->date('age_cutoff_date')->nullable();
            $table->text('age_relaxation')->nullable();
            $table->longText('essential_qualifications')->nullable();
            $table->longText('desirable_qualifications')->nullable();
            $table->longText('experience_requirements')->nullable();
            $table->longText('other_conditions')->nullable();
            $table->json('fee_schedule')->nullable(); // overrides the advertisement's
            $table->string('endorsement_mode', 30)->nullable(); // overrides the advertisement's
            $table->string('status', 20)->default('open'); // open | closed
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['advertisement_id', 'code']);
        });

        Schema::create('post_vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20); // UR, SC, ST, OBC, EWS, PwBD, ESM ...
            $table->unsignedSmallInteger('count');
            $table->string('remarks')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'category']);
        });

        Schema::create('post_eligibility_criteria', function (Blueprint $table) {
            // Advisory checks shown to scrutiny. They never reject automatically.
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40); // min_percentage, min_experience_months, max_age, qualification_level, custom
            $table->string('label', 300);
            $table->string('value', 100)->nullable();
            $table->string('qualification_level', 40)->nullable();
            $table->boolean('is_essential')->default(true);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            // always | reserved_category | pwd | ex_serviceman | in_service | has_employment | criminal_disclosure | fee_payable
            $table->string('condition', 40)->default('always');
            $table->boolean('is_mandatory')->default(true);
            $table->string('instructions', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('copied_from_id')->nullable()->constrained('checklist_templates')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 500);
            $table->text('verification_instructions')->nullable();
            // personal, fee, qualification, employment, category, disability, ex_serviceman,
            // endorsement, referee, declaration, identity, other
            $table->string('section', 40)->default('other');
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('requires_reason_if_not_verified')->default(true);
            $table->boolean('applicant_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['checklist_template_id', 'code']);
        });

        Schema::create('checklist_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['checklist_template_id', 'advertisement_id', 'post_id'], 'checklist_assign_unique');
        });

        Schema::create('scrutiny_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('role', 50); // role slug that acts at this stage
            $table->boolean('is_decision_stage')->default(false);
            $table->boolean('allow_same_reviewer')->default(false);
            $table->timestamps();
            $table->unique(['advertisement_id', 'sequence']);
            $table->unique(['advertisement_id', 'code']);
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 150);
            $table->string('subject', 300);
            $table->longText('body');
            $table->json('placeholders')->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'email_templates', 'scrutiny_stages', 'checklist_assignments', 'checklist_items',
            'checklist_templates', 'document_requirements', 'post_eligibility_criteria',
            'post_vacancies', 'posts', 'advertisement_user', 'advertisement_documents',
            'advertisements', 'evaluation_form_fields', 'evaluation_form_templates',
            'referee_questions', 'referee_form_templates', 'document_types',
            'recruitment_cycles', 'settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
