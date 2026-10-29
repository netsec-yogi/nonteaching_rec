<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Mandatory setting under Admin → Document Types now applies to every advertisement that has
 * no requirement of its own for that document type (with the type's "required when" condition);
 * an advertisement requirement still overrides it. Before this, a type without an advertisement
 * requirement was always shown and treated as optional, whatever its setting.
 *
 * Data changes, chosen so that no applicant is suddenly asked for a document nobody requested:
 *  - a "required when" condition per document type (sensible defaults for the seeded codes);
 *  - document types that no advertisement requires, and whose Mandatory setting no administrator
 *    ever saved (no audit entry), become non-mandatory — today they are optional everywhere;
 *    types an administrator deliberately set keep that setting and now take effect;
 *  - advertisement requirements whose explicit value equals the type's setting follow the type
 *    from now on (no change in effect); differing values remain advertisement overrides;
 *  - the employer testimonial, whose upload was removed from the referees step, is deactivated
 *    (existing uploads stay and remain visible to staff).
 */
return new class extends Migration
{
    private const CONDITIONS = [
        'fee_receipt' => 'fee_payable', 'category_certificate' => 'reserved_category', 'disability_certificate' => 'pwd',
        'esm_certificate' => 'ex_serviceman', 'experience_certificate' => 'has_employment', 'analogous_post_proof' => 'has_employment',
        'case_details' => 'criminal_disclosure', 'declaration_details' => 'criminal_disclosure', 'apar_copies' => 'in_service',
        'employer_endorsement' => 'in_service', 'noc' => 'in_service',
    ];

    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('condition', 40)->default('always')->after('is_mandatory');
        });
        foreach (self::CONDITIONS as $code => $condition) {
            DB::table('document_types')->where('code', $code)->update(['condition' => $condition]);
        }

        $required = DB::table('document_requirements')->distinct()->pluck('document_type_id');
        $deliberate = DB::table('audit_logs')->where('auditable_type', 'document_type')
            ->whereIn('action', ['document_type.created', 'document_type.updated'])
            ->where('new_values', 'like', '%"is_mandatory"%')
            ->distinct()->pluck('auditable_id');
        DB::table('document_types')->whereNotIn('id', $required)->whereNotIn('id', $deliberate)->update(['is_mandatory' => false]);

        DB::table('document_requirements')->whereNotNull('is_mandatory')
            ->whereRaw('is_mandatory = (SELECT document_types.is_mandatory FROM document_types WHERE document_types.id = document_requirements.document_type_id)')
            ->update(['is_mandatory' => null]);

        DB::table('document_types')->where('code', 'employer_testimonial')->update(['is_active' => false, 'is_mandatory' => false]);
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('condition'));
    }
};
