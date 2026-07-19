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
        Schema::create('xray_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('xray_record_id')->constrained('xray_records')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('storage_path');
            $table->string('mime_type');
            $table->unsignedInteger('file_size_bytes');
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('xr_attach_up_by_fk');

            $table->timestamp('uploaded_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('xray_attachments');
    }
};
