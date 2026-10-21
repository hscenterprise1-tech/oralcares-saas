<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription Studio on the one prescription model (`prescriptions`).
 *
 * - A prescription is drafted in a consultation and finalised (signed) by the
 *   prescriber. Its number is issued when it is finalised, so a discarded
 *   draft never burns a number; a finalised one is never edited, only
 *   cancelled with a reason.
 * - Lines carry the medicine as written (name, form, strength, dose,
 *   frequency, duration, timing, route), and the clinic's own formulary
 *   (`clinic_medicines`) remembers what its doctors prescribe so the next
 *   prescription starts from it.
 * - Lines are clinic-owned and can only hang off a prescription of the same
 *   clinic (composite foreign key).
 *
 * Legacy rows written as `active` were issued on creation; they become
 * `finalized`, keeping their number and creation time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('generic_name', 150)->nullable();
            $table->string('form', 30)->nullable();
            $table->string('strength', 40)->nullable();
            $table->string('lookup_key', 255);
            $table->string('default_dosage', 60)->nullable();
            $table->string('default_frequency', 30)->nullable();
            $table->string('default_duration', 40)->nullable();
            $table->string('default_timing', 30)->nullable();
            $table->string('default_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['clinic_id', 'lookup_key']);
            $table->index(['clinic_id', 'is_active', 'use_count']);
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('prescription_number')->nullable()->change();
            $table->string('status')->default('draft')->change();
            $table->foreignId('prescriber_id')->nullable()->after('prescribed_by')->constrained('clinic_employees')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->char('signature_hash', 64)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('clinic_employees')->restrictOnDelete();
            $table->string('cancellation_reason')->nullable();

            $table->unique(['id', 'clinic_id'], 'prescriptions_id_clinic_unique');
            $table->index(['clinic_id', 'patient_id', 'status'], 'prescriptions_patient_status_index');
        });

        DB::table('prescriptions')->where('status', 'active')->update([
            'status' => 'finalized',
            'finalized_at' => DB::raw('created_at'),
        ]);

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id');
            $table->foreignId('medicine_id')->nullable()->after('drug_master_id')->constrained('clinic_medicines')->nullOnDelete();
            $table->string('form', 30)->nullable()->after('drug_name');
            $table->string('strength', 40)->nullable()->after('form');
            $table->string('route', 30)->nullable()->after('duration');
            $table->string('timing', 30)->nullable()->after('route');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        DB::table('prescription_items')->update([
            'clinic_id' => DB::raw('(select clinic_id from prescriptions where prescriptions.id = prescription_items.prescription_id)'),
        ]);

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
            $table->foreign('clinic_id')->references('id')->on('clinics')->restrictOnDelete();
            $table->foreign(['prescription_id', 'clinic_id'], 'prescription_items_same_clinic_fk')
                ->references(['id', 'clinic_id'])->on('prescriptions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropForeign('prescription_items_same_clinic_fk');
            $table->dropForeign(['clinic_id']);
            $table->dropConstrainedForeignId('medicine_id');
            $table->dropColumn(['clinic_id', 'form', 'strength', 'route', 'timing', 'sort_order']);
        });

        // MySQL keeps an index on `prescription_id` only through the composite
        // key; restore the single-column one its own foreign key needs.
        if (! collect(Schema::getIndexes('prescription_items'))->contains(fn (array $index) => $index['columns'] === ['prescription_id'])) {
            Schema::table('prescription_items', fn (Blueprint $table) => $table->index('prescription_id'));
        }

        DB::table('prescriptions')->where('status', 'finalized')->update(['status' => 'active']);
        DB::table('prescriptions')->whereNull('prescription_number')->delete();

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropIndex('prescriptions_patient_status_index');
            $table->dropUnique('prescriptions_id_clinic_unique');
            $table->dropConstrainedForeignId('prescriber_id');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['finalized_at', 'signature_hash', 'cancelled_at', 'cancellation_reason']);
            $table->string('status')->default('active')->change();
            $table->string('prescription_number')->nullable(false)->change();
        });

        Schema::dropIfExists('clinic_medicines');
    }
};
