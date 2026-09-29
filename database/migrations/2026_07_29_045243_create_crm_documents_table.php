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
        Schema::create('crm_documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable'); // Generates documentable_id and documentable_type
            $table->string('file_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('mime_type')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_documents');
    }
};
