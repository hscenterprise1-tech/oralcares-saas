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
        Schema::create('diagnostic_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('clinical_order_item_id')->constrained('clinical_order_items')->cascadeOnDelete();

            $table->string('status')->default('draft'); // draft, preliminary, final, amended, cancelled
            $table->string('result_type'); // laboratory, imaging, pathology, other

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->json('content')->nullable(); // Stores actual report text or lab observations (key-value)

            $table->string('hl7_message_id')->nullable();
            $table->string('dicom_study_uid')->nullable();

            $table->dateTime('reported_at')->nullable();
            $table->dateTime('verified_at')->nullable();

            $table->timestamps();
        });

        Schema::create('clinical_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->morphs('attachable'); // attachable_type, attachable_id

            $table->string('file_path');
            $table->string('file_type'); // image, pdf, dicom_link, document
            $table->string('title');
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_attachments');
        Schema::dropIfExists('diagnostic_results');
    }
};
