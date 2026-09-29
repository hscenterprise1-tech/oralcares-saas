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
        Schema::create('marketing_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->json('rules');
            $table->string('status')->default('active')->index(); // active, paused
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_audiences');
    }
};
