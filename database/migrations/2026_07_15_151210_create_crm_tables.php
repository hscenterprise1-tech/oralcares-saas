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
        // 1. Teams table (platform company workgroups)
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        // 2. Team users pivot
        Schema::create('team_users', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['team_id', 'user_id']);
        });

        // 3. Leads table
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('slug')->unique();
            $table->string('contact_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('new');
            $table->string('source')->default('manual_entry');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('assigned_user_id');
            $table->index('assigned_team_id');
            $table->index('email');
            $table->index('source');
            $table->index('created_at');
            $table->index('updated_at');
            $table->index(['status', 'assigned_user_id']);
            $table->index(['status', 'assigned_team_id']);
        });

        // 4. Lead activities timeline
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->text('description');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('lead_id');
            $table->index('created_at');
        });

        // 5. Tasks table
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->dateTime('due_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('taskable');
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('due_at');
            $table->index('assigned_user_id');
            $table->index('assigned_team_id');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('team_users');
        Schema::dropIfExists('teams');
    }
};
