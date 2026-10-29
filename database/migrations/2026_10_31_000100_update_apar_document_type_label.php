<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Label change requested for the Section 30 upload. Only the seeded wording is replaced, so a name
 * an administrator has already changed under Admin → Document Types is left alone. Submitted
 * application versions keep the wording they were frozen with.
 */
return new class extends Migration
{
    private const OLD = 'Certified copies of ACRs / APARs for the last five years';

    private const NEW = 'Certified copies of ACRs / APARs for the last five years(If applicable)';

    public function up(): void
    {
        DB::table('document_types')->where('code', 'apar_copies')->where('name', self::OLD)->update(['name' => self::NEW, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('document_types')->where('code', 'apar_copies')->where('name', self::NEW)->update(['name' => self::OLD, 'updated_at' => now()]);
    }
};
