<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('patient_procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained('clinical_encounters')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('procedure_master_id')->nullable()->constrained('procedure_masters')->nullOnDelete();

            $table->string('code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('details')->nullable();

            $table->string('status')->default('planned');

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_procedures');
    }
};
