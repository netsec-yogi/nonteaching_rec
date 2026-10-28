<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections from the October 2026 audit, both additive and safe on live data:
 *  - paper item 32 addresses the applicant as Dr/Mr/Mrs/Ms; the salutation is now captured;
 *  - frozen application versions are the record of what was submitted, so the database refuses
 *    to cascade-delete them with the application (the models already refuse updates/deletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_personal_details', function (Blueprint $table) {
            $table->string('salutation', 10)->nullable()->after('application_id');
        });

        Schema::table('application_versions', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->foreign('application_id')->references('id')->on('applications')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('application_versions', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
        });

        Schema::table('application_personal_details', function (Blueprint $table) {
            $table->dropColumn('salutation');
        });
    }
};
