<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application and every numbered section of the paper form.
 *
 * Section numbers in comments refer to Application-Form-Non-Teaching (12.09.2025).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->foreignId('post_id')->constrained()->restrictOnDelete();
            $table->string('reference_no', 60)->nullable()->unique();

            // Lifecycle: draft | submitted | reopened | withdrawn
            $table->string('status', 20)->default('draft')->index();
            // submitted | received | assigned | in_progress | clarification_required | clarification_received
            // | completed | under_review | decided
            $table->string('scrutiny_status', 30)->nullable()->index();
            $table->foreignId('current_stage_id')->nullable()->constrained('scrutiny_stages')->nullOnDelete();
            // recommended | incomplete | not_recommended
            $table->string('outcome', 30)->nullable()->index();
            // pending | recommended | not_recommended | further_review
            $table->string('committee_status', 30)->nullable()->index();
            // not_submitted | pending_verification | verified | rejected | exempt
            $table->string('fee_status', 30)->default('not_submitted')->index();
            // not_applicable | pending | submitted | verified | rejected
            $table->string('endorsement_status', 30)->default('not_applicable')->index();
            // not_started | pending | partial | complete | exception
            $table->string('referee_status', 30)->default('not_started')->index();
            // pending | verified | deficient
            $table->string('document_status', 30)->default('pending')->index();

            $table->string('current_step', 40)->nullable();
            $table->json('completed_steps')->nullable();
            $table->date('application_date')->nullable(); // Section 3

            $table->boolean('is_in_service')->default(false);
            $table->boolean('has_employment')->nullable();        // Section 19
            $table->boolean('has_merit_records')->nullable();     // Section 21
            $table->boolean('has_training_records')->nullable();  // Section 22
            $table->boolean('has_memberships')->nullable();       // Section 23
            $table->longText('suitability_statement')->nullable(); // Section 24
            $table->longText('other_achievements')->nullable();   // Section 31

            $table->timestamp('referees_confirmed_at')->nullable();
            $table->boolean('referee_email_exception')->default(false);
            $table->string('referee_exception_note', 500)->nullable();
            $table->boolean('referee_requirement_waived')->default(false);
            $table->string('referee_waiver_reason', 500)->nullable();

            // Candidate declaration (page 3)
            $table->string('declaration_place', 100)->nullable();
            $table->date('declaration_date')->nullable();
            $table->timestamp('declaration_accepted_at')->nullable();
            $table->string('declaration_version', 30)->nullable();
            $table->string('declaration_ip', 45)->nullable();

            $table->unsignedSmallInteger('version')->default(0);
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('first_submitted_at')->nullable();
            $table->timestamp('reopened_until')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawal_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'post_id']);
            $table->index(['advertisement_id', 'post_id', 'status']);
        });

        Schema::create('application_personal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('full_name', 200)->nullable();                 // 5
            $table->string('relation_type', 10)->nullable();              // 6: father | husband
            $table->string('relation_name', 200)->nullable();
            $table->date('date_of_birth')->nullable();                    // 7
            $table->string('place_of_birth', 150)->nullable();
            $table->string('mobile', 15)->nullable();                     // 8
            $table->string('email')->nullable();
            $table->string('nationality', 60)->nullable();                // 9
            $table->string('gender', 20)->nullable();                     // 10
            $table->string('marital_status', 20)->nullable();             // 11
            $table->string('category', 10)->nullable()->index();          // 12
            $table->boolean('is_pwd')->nullable();                        // 13
            $table->string('disability_type', 150)->nullable();
            $table->unsignedTinyInteger('disability_percentage')->nullable();
            $table->string('nearest_railway_station', 150)->nullable();   // 14
            $table->boolean('permanent_same_as_correspondence')->default(false);
            $table->boolean('is_ex_serviceman')->nullable();              // 17
            $table->string('ex_serviceman_category', 150)->nullable();
            $table->text('ex_serviceman_details')->nullable();
            $table->timestamps();
        });

        Schema::create('application_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // correspondence | permanent  (15, 16)
            $table->string('line1', 255)->nullable();
            $table->string('line2', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('country', 60)->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'type']);
        });

        Schema::create('application_qualifications', function (Blueprint $table) {
            $table->id();                                                 // 18
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('level', 30); // class_10, class_12, diploma, graduation, post_graduation, doctorate, professional, technical, training, other
            $table->string('examination', 200);
            $table->string('board_university', 250);
            $table->string('duration', 60)->nullable();
            $table->unsignedSmallInteger('year_of_passing');
            $table->string('division', 30)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->string('subjects', 500)->nullable();
            $table->string('specialization', 250)->nullable();
            $table->timestamps();
            $table->index(['application_id', 'level']);
        });

        Schema::create('application_employments', function (Blueprint $table) {
            $table->id();                                                 // 19
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('organization', 250);
            $table->string('post_held', 200);
            $table->string('employment_type', 20); // regular | temporary | permanent | contract
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->unsignedSmallInteger('duration_months')->default(0);
            $table->string('pay_scale_level', 150)->nullable();
            $table->decimal('basic_pay', 12, 2)->nullable();
            $table->decimal('gross_pay', 12, 2)->nullable();
            $table->text('nature_of_duties')->nullable();
            $table->timestamps();
        });

        Schema::create('application_merit_records', function (Blueprint $table) {
            $table->id();                                                 // 21
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title', 250);
            $table->string('awarded_by', 250)->nullable();
            $table->date('awarded_on')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('application_trainings', function (Blueprint $table) {
            $table->id();                                                 // 22
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('type', 20); // conducted | attended | consultancy
            $table->string('title', 250);
            $table->string('organization', 250)->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('duration', 60)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('application_memberships', function (Blueprint $table) {
            $table->id();                                                 // 23
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('institute_name', 250);
            $table->string('membership_category', 150)->nullable();
            $table->string('membership_number', 100)->nullable();
            $table->date('member_since')->nullable();
            $table->timestamps();
        });

        Schema::create('application_declarations', function (Blueprint $table) {
            // Sections 20, 26(a), 26(b), 27, 28, 29, 30 — one row per question.
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->boolean('answer')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'code']);
        });

        Schema::create('referees', function (Blueprint $table) {
            $table->id();                                                 // 25
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('name', 200);
            $table->string('designation', 200);
            $table->string('organization', 250);
            $table->text('address');
            $table->string('phone', 20);
            $table->string('email');
            $table->string('relationship', 200)->nullable();
            $table->unsignedTinyInteger('years_known')->nullable();
            $table->boolean('is_resident_of_india')->default(false);
            $table->boolean('is_not_relative')->default(false);
            $table->boolean('is_employer_or_superior')->default(false);
            $table->timestamps();
            $table->unique(['application_id', 'position']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();                                                 // 1
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20); // neft | demand_draft | online | exempt
            $table->string('utr_number', 60)->nullable();
            $table->date('transaction_date')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->string('bank_name', 200)->nullable();
            $table->string('exemption_basis', 100)->nullable();
            // pending_verification | verified | rejected | exempt
            $table->string('status', 30)->default('pending_verification');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();
            $table->unique('application_id');
            $table->index('utr_number');
        });

        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('employer_endorsements', function (Blueprint $table) {
            $table->id();                                                 // 32
            $table->foreignId('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employer_organization', 250)->nullable();
            $table->string('designation', 200)->nullable();
            $table->string('employment_type', 20)->nullable();
            $table->date('employment_start_date')->nullable();
            $table->string('pay_scale_level', 150)->nullable();
            $table->decimal('basic_pay', 12, 2)->nullable();
            $table->date('next_increment_date')->nullable();
            $table->boolean('no_disciplinary_case_certified')->default(false);
            $table->boolean('no_objection_certified')->default(false);
            $table->string('forwarding_officer_name', 200)->nullable();
            $table->string('forwarding_officer_designation', 200)->nullable();
            $table->string('place', 100)->nullable();
            $table->date('endorsed_on')->nullable();
            // pending | submitted | verified | rejected
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('documentable'); // a qualification row, an employment row, ...
            $table->unsignedBigInteger('clarification_response_id')->nullable()->index();
            $table->string('label', 250)->nullable();
            $table->string('original_name');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            // pending | verified | rejected
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_remarks', 500)->nullable();
            $table->boolean('is_frozen')->default(false); // part of a submitted snapshot
            $table->timestamps();
            $table->softDeletes();
            $table->index(['application_id', 'document_type_id']);
        });

        Schema::create('application_checklist_entries', function (Blueprint $table) {
            // Step 17 — the page-5 checklist, as filled in by the candidate.
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();
            $table->string('candidate_status', 10)->nullable(); // yes | no | na
            $table->string('page_reference', 100)->nullable();
            $table->foreignId('document_id')->nullable()->constrained('application_documents')->nullOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'checklist_item_id'], 'app_checklist_entry_unique');
        });

        Schema::create('application_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');
            $table->string('pdf_disk', 30)->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['application_id', 'version']);
        });

        Schema::create('application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('field', 30);
            $table->string('from_value', 40)->nullable();
            $table->string('to_value', 40)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('application_reopenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reopened_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('sections')->nullable();
            $table->unsignedSmallInteger('from_version');
            $table->timestamp('reopened_until')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'application_reopenings', 'application_status_histories', 'application_versions',
            'application_checklist_entries', 'application_documents', 'employer_endorsements',
            'payment_verifications', 'payments', 'referees', 'application_declarations',
            'application_memberships', 'application_trainings', 'application_merit_records',
            'application_employments', 'application_qualifications', 'application_addresses',
            'application_personal_details', 'applications',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
