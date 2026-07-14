<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_membership_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_membership_id')->constrained('clinic_memberships')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinic_membership_id', 'role_id', 'branch_id'], 'cm_role_branch_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_membership_roles');
    }
};
