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
        // 1. Lab Prosthetics Catalog
        Schema::create('lab_prosthetics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });

        // 2. Lab Materials Catalog
        Schema::create('lab_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        // 3. Lab Shades Catalog
        Schema::create('lab_shades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained('clinics')->cascadeOnDelete();
            $table->string('system'); // Vita Classical, Vita 3D Master, Custom Shade
            $table->string('code');
            $table->timestamps();
        });

        // 4. Alter lab_orders table to support clinical workflows
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->foreignId('treatment_record_id')
                ->nullable()
                ->constrained('treatment_records')
                ->nullOnDelete()
                ->name('lo_tx_rec_fk');

            $table->foreignId('requesting_doctor_id')
                ->nullable()
                ->constrained('clinic_employees')
                ->nullOnDelete()
                ->name('lo_doc_fk');

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete()
                ->name('lo_branch_fk');

            $table->string('laboratory_name')->nullable();
            $table->boolean('is_internal')->default(false);

            $table->foreignId('lab_prosthetic_id')
                ->nullable()
                ->constrained('lab_prosthetics')
                ->nullOnDelete()
                ->name('lo_prosthetic_fk');

            $table->foreignId('lab_material_id')
                ->nullable()
                ->constrained('lab_materials')
                ->nullOnDelete()
                ->name('lo_material_fk');

            $table->foreignId('lab_shade_id')
                ->nullable()
                ->constrained('lab_shades')
                ->nullOnDelete()
                ->name('lo_shade_fk');

            $table->json('requested_teeth')->nullable(); // FDI teeth array
            $table->date('delivery_due_date')->nullable();
        });

        // 5. Lab Case Trials
        Schema::create('lab_case_trials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('lab_orders')->cascadeOnDelete();
            $table->string('trial_type'); // wax_trial, metal_trial, bisque_trial, final_fitting
            $table->timestamp('scheduled_at');
            $table->text('notes')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
        });

        // 6. Lab Order Attachments
        Schema::create('lab_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained('lab_orders')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('storage_path');
            $table->string('file_type'); // photo, design_file, stl_reference, scan_reference, lab_notes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_order_attachments');
        Schema::dropIfExists('lab_case_trials');

        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropForeign(['lab_shade_id']);
            $table->dropForeign(['lab_material_id']);
            $table->dropForeign(['lab_prosthetic_id']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['requesting_doctor_id']);
            $table->dropForeign(['treatment_record_id']);

            $table->dropColumn([
                'treatment_record_id',
                'requesting_doctor_id',
                'branch_id',
                'laboratory_name',
                'is_internal',
                'lab_prosthetic_id',
                'lab_material_id',
                'lab_shade_id',
                'requested_teeth',
                'delivery_due_date',
            ]);
        });

        Schema::dropIfExists('lab_shades');
        Schema::dropIfExists('lab_materials');
        Schema::dropIfExists('lab_prosthetics');
    }
};
