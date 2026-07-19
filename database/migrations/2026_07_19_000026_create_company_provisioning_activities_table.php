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
        Schema::create('company_provisioning_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_provisioning_process_id')
                ->constrained('company_provisioning_processes')
                ->cascadeOnDelete()
                ->name('co_prov_act_proc_fk');

            $table->string('event');
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_provisioning_activities');
    }
};
