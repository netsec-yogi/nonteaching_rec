<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only — no existing row or file is changed except where noted:
 *  - PAN / Aadhaar numbers on the personal details (optional, encrypted at rest by the model);
 *    advertisements say whether either is required (default: optional).
 *  - Signed undertakings in place of a missing document: document types say whether one is
 *    accepted (default no); an advertisement requirement may override it (null = follow the type).
 *    Each uploaded file records whether it is the original or an undertaking, and for an
 *    undertaking whether the original is still awaited and when it was received and verified.
 *    Existing uploads become 'original', which is what they are.
 *  - Certificate-type documents (codes below) accept undertakings by default, the case the
 *    feature exists for; administrators can switch any type on or off under Document Types.
 */
return new class extends Migration
{
    private const UNDERTAKING_DEFAULT = ['qualification_certificate', 'experience_certificate', 'category_certificate',
        'disability_certificate', 'esm_certificate', 'analogous_post_proof', 'noc', 'desirable_qualification_proof'];

    public function up(): void
    {
        Schema::table('application_personal_details', function (Blueprint $table) {
            $table->text('pan_number')->nullable()->after('nearest_railway_station');
            $table->text('aadhaar_number')->nullable()->after('pan_number');
        });

        Schema::table('advertisements', function (Blueprint $table) {
            // optional | pan | aadhaar | pan_or_aadhaar
            $table->string('identity_number_requirement', 20)->default('optional')->after('payment_methods');
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('accepts_undertaking')->default(false)->after('is_mandatory');
        });
        DB::table('document_types')->whereIn('code', self::UNDERTAKING_DEFAULT)->update(['accepts_undertaking' => true]);

        Schema::table('document_requirements', function (Blueprint $table) {
            $table->boolean('accepts_undertaking')->nullable()->after('is_mandatory');
        });

        Schema::table('application_documents', function (Blueprint $table) {
            $table->string('upload_type', 20)->default('original')->after('document_type_id'); // original | undertaking
            // For an undertaking: awaited | received_verified | received_rejected
            $table->string('original_status', 30)->nullable()->after('verification_remarks');
            $table->string('original_due_stage', 60)->nullable()->after('original_status');
            $table->timestamp('original_received_at')->nullable()->after('original_due_stage');
            $table->foreignId('original_received_by')->nullable()->after('original_received_at')->constrained('users')->nullOnDelete();
            $table->string('original_remarks', 500)->nullable()->after('original_received_by');
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('original_received_by');
            $table->dropColumn(['upload_type', 'original_status', 'original_due_stage', 'original_received_at', 'original_remarks']);
        });
        Schema::table('document_requirements', fn (Blueprint $table) => $table->dropColumn('accepts_undertaking'));
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('accepts_undertaking'));
        Schema::table('advertisements', fn (Blueprint $table) => $table->dropColumn('identity_number_requirement'));
        Schema::table('application_personal_details', fn (Blueprint $table) => $table->dropColumn(['pan_number', 'aadhaar_number']));
    }
};
