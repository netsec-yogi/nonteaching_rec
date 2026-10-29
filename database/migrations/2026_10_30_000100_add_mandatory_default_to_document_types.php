<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mandatory / non-mandatory per document type, as the default for every advertisement's
 * requirement of that type; a requirement can still override it (document_requirements.is_mandatory,
 * now nullable: null = follow the document type). No data is lost:
 *  - every existing requirement keeps its explicit mandatory value, so nothing currently required
 *    or optional changes;
 *  - a document type is marked non-mandatory only where every existing requirement of it is
 *    already optional; all others default to mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(true)->after('max_kb');
        });

        $optionalEverywhere = DB::table('document_requirements')->select('document_type_id')
            ->groupBy('document_type_id')->havingRaw('MAX(is_mandatory) = 0')->pluck('document_type_id');
        if ($optionalEverywhere->isNotEmpty()) {
            DB::table('document_types')->whereIn('id', $optionalEverywhere)->update(['is_mandatory' => false]);
        }

        Schema::table('document_requirements', function (Blueprint $table) {
            $table->boolean('is_mandatory')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Requirements that followed their document type get its value written in explicitly.
        DB::table('document_requirements')->whereNull('is_mandatory')->update([
            'is_mandatory' => DB::raw('(SELECT document_types.is_mandatory FROM document_types WHERE document_types.id = document_requirements.document_type_id)'),
        ]);
        Schema::table('document_requirements', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(true)->nullable(false)->change();
        });
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('is_mandatory');
        });
    }
};
