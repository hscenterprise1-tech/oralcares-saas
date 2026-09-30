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
        Schema::create('marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name')->index();
            $table->string('phone');
            $table->string('normalized_phone')->index();
            $table->string('organization')->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->string('marketing_status')->default('active')->index(); // active, opted_out, suppressed
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('marketing_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->index();
            $table->string('color')->default('neutral');
            $table->timestamps();
        });

        Schema::create('marketing_contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('marketing_tags')->cascadeOnDelete();
            $table->primary(['contact_id', 'tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_contact_tag');
        Schema::dropIfExists('marketing_tags');
        Schema::dropIfExists('marketing_contacts');
    }
};
