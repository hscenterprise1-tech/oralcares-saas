<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dental lab workflow as the clinic runs it — ordered → sent → in
 * progress → received → fitted → completed (or cancelled) — with when each
 * step happened, and the restoration, shade and material written in when the
 * clinic has not set up its lab masters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->string('restoration', 150)->nullable();
            $table->string('shade', 60)->nullable();
            $table->string('material', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('fitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->index(['clinic_id', 'patient_id', 'status'], 'lab_orders_patient_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropIndex('lab_orders_patient_status_index');
            $table->dropColumn(['restoration', 'shade', 'material', 'sent_at', 'received_at', 'fitted_at', 'completed_at', 'cancelled_at', 'cancellation_reason']);
        });
    }
};
