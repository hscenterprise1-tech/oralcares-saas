<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per database backup attempt and its later restore test. A
     * backup counts as usable only when `verification_status` is `passed`,
     * meaning it was decrypted, restored into a scratch database and matched
     * its manifest. A file existing is not enough.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20); // running | succeeded | failed | pruned
            $table->string('disk', 64)->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('verification_status', 20)->nullable(); // passed | failed
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_report')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['verification_status', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
