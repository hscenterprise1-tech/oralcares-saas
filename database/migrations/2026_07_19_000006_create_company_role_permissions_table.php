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
        Schema::create('company_role_permissions', function (Blueprint $table) {
            $table->foreignId('company_role_id')->constrained('company_roles')->cascadeOnDelete();
            $table->foreignId('company_permission_id')->constrained('company_permissions')->cascadeOnDelete();

            $table->primary(['company_role_id', 'company_permission_id'], 'co_role_perm_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_role_permissions');
    }
};
