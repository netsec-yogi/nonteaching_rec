<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank details and QR code applicants use to pay the application fee, configured per
 * advertisement (optionally per post). Additive only: existing payments keep working and
 * simply have no configuration reference.
 *
 *  - fee_payment_configs: the editable configuration; `version` increases on every change.
 *  - fee_payment_config_revisions: append-only copy of each version, with who changed it.
 *  - payments.*: the configuration version an applicant paid against, frozen with the payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertisement_id')->constrained()->restrictOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->restrictOnDelete(); // null = whole advertisement
            $table->string('label', 150)->nullable();
            $table->string('account_holder', 200)->nullable();
            $table->string('bank_name', 200)->nullable();
            $table->string('branch_name', 200)->nullable();
            $table->text('account_number')->nullable(); // encrypted at rest
            $table->string('account_type', 30)->nullable();
            $table->string('ifsc', 11)->nullable();
            $table->string('branch_address', 500)->nullable();
            $table->string('upi_id', 100)->nullable();
            $table->text('instructions')->nullable();
            $table->text('reference_instructions')->nullable();
            $table->dateTime('payment_deadline')->nullable();
            $table->string('qr_disk', 30)->nullable();
            $table->string('qr_path', 255)->nullable();
            $table->string('qr_mime', 50)->nullable();
            $table->unsignedInteger('qr_size')->nullable();
            $table->unsignedSmallInteger('qr_width')->nullable();
            $table->unsignedSmallInteger('qr_height')->nullable();
            $table->char('qr_sha256', 64)->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['advertisement_id', 'post_id', 'is_active']);
        });

        Schema::create('fee_payment_config_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_payment_config_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 30); // created | updated | activated | deactivated
            $table->longText('snapshot'); // encrypted
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['fee_payment_config_id', 'version'], 'fee_config_revisions_version_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('fee_payment_config_id')->nullable()->after('bank_name')->constrained()->nullOnDelete();
            $table->unsignedInteger('fee_payment_config_version')->nullable()->after('fee_payment_config_id');
            $table->longText('payment_config_snapshot')->nullable()->after('fee_payment_config_version'); // encrypted
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_payment_config_id');
            $table->dropColumn(['fee_payment_config_version', 'payment_config_snapshot']);
        });
        Schema::dropIfExists('fee_payment_config_revisions');
        Schema::dropIfExists('fee_payment_configs');
    }
};
