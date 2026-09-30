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
        Schema::table('xray_records', function (Blueprint $table) {
            $table->string('category')->default('opg'); // iopa, opg, cbct, bitewing, etc.
            $table->foreignId('requesting_doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('xr_req_doc_fk');

            $table->string('requested_region')->default('entire_mouth'); // single_tooth, upper_jaw, etc.
            $table->json('requested_teeth')->nullable(); // FDI tooth numbers array
            $table->text('clinical_reason')->nullable();
            $table->string('priority')->default('normal'); // normal, urgent, emergency

            $table->text('findings')->nullable();
            $table->text('impression')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('xray_records', function (Blueprint $table) {
            $table->dropForeign(['requesting_doctor_id']);
            $table->dropColumn([
                'category',
                'requesting_doctor_id',
                'requested_region',
                'requested_teeth',
                'clinical_reason',
                'priority',
                'findings',
                'impression',
                'reviewed_at',
            ]);
        });
    }
};
