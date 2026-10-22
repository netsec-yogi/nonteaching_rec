<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Records-retention tracking: when the period ended and when (and by whom) data was disposed of. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->timestamp('retention_due_at')->nullable()->after('archived_at');
            $table->timestamp('disposed_at')->nullable()->after('retention_due_at');
            $table->foreignId('disposed_by')->nullable()->after('disposed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disposed_by');
            $table->dropColumn(['retention_due_at', 'disposed_at']);
        });
    }
};
