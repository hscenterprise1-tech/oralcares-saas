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
        // 1. Alter clinic_queue_items to add status and routing info
        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->string('status')->default('waiting'); // waiting, calling, called, recalled, skipped, held, completed, etc.
            $table->string('current_floor')->nullable();
            $table->string('current_department')->nullable();
        });

        // 2. Queue Display Sessions
        Schema::create('queue_displays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('floor')->nullable();
            $table->string('department')->nullable();
            $table->string('display_name');
            $table->string('display_type'); // reception, doctor, floor, branch, laboratory, xray
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Queue History (Immutable Log)
        Schema::create('queue_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('clinic_queue_item_id')->constrained('clinic_queue_items')->cascadeOnDelete();

            $table->string('action'); // called, recalled, skipped, held, resumed, completed
            $table->foreignId('performed_by')->nullable()->constrained('clinic_employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_histories');
        Schema::dropIfExists('queue_displays');

        Schema::table('clinic_queue_items', function (Blueprint $table) {
            $table->dropColumn(['status', 'current_floor', 'current_department']);
        });
    }
};
