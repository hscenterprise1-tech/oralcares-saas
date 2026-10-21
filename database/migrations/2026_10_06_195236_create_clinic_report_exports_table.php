<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A clinic report exported to CSV in the background: what was asked for,
 * by whom, and the private file once it is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained('clinics')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report', 40);
            $table->json('filters');
            // queued → ready | failed
            $table->string('status', 10)->default('queued');
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('failure', 255)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'requested_by', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_report_exports');
    }
};
