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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('scheduled_at');
            $table->integer('duration_minutes')->default(15);
            $table->string('status')->default('scheduled'); // scheduled, checked_in, completed, cancelled, no_show
            $table->string('appointment_type')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });

        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();

            $table->string('status')->default('waiting'); // waiting, in_consultation, completed
            $table->integer('priority')->default(0);

            $table->dateTime('check_in_time')->nullable();
            $table->dateTime('called_time')->nullable();
            $table->dateTime('completed_time')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
        Schema::dropIfExists('appointments');
    }
};
