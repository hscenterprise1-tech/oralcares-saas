<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dental treatment that takes several visits (root canal, crown, implant): how many it was planned for, and one row per
     * sitting actually done, with the stage it covered, the doctor's note and what was charged for it.
     */
    public function up(): void
    {
        Schema::table('treatment_records', function (Blueprint $table) {
            $table->unsignedTinyInteger('sittings_total')->nullable()->after('price');
        });

        Schema::create('treatment_record_sittings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_record_id')->constrained('treatment_records')->cascadeOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('clinical_encounters')->nullOnDelete();
            $table->foreignId('patient_procedure_id')->nullable()->constrained('patient_procedures')->nullOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('stage', 120);
            $table->text('note')->nullable();
            $table->decimal('charged', 10, 2)->nullable();
            $table->timestamp('done_at');
            $table->timestamps();

            $table->unique(['treatment_record_id', 'number']);
            $table->index(['clinic_id', 'treatment_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_record_sittings');
        Schema::table('treatment_records', function (Blueprint $table) {
            $table->dropColumn('sittings_total');
        });
    }
};
