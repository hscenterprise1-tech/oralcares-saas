<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one clinic's owner has changed from a role's standard permissions.
     * The standard roles (`ClinicRole`) are shared by every clinic and are
     * never edited; a row here is one clinic's choice to give a role a
     * permission it does not have by default, or to take one away.
     */
    public function up(): void
    {
        Schema::create('clinic_role_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('role_name', 60);
            $table->string('permission', 80);
            $table->boolean('granted');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'role_name', 'permission'], 'clinic_role_permission_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_role_permission_overrides');
    }
};
