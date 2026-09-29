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
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('plan_name')->default('starter');
            $table->integer('max_branches')->default(1);
            $table->integer('max_users')->default(1);
            $table->integer('storage_limit_mb')->default(1024);
            $table->dateTime('expires_at')->nullable();
            $table->string('status')->default('active'); // active, suspended, expired
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
