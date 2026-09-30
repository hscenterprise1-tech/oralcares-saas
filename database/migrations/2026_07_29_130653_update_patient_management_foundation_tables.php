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
        // 1. Ensure organization_id on patient_profiles
        if (! Schema::hasColumn('patient_profiles', 'organization_id')) {
            Schema::table('patient_profiles', function (Blueprint $table) {
                $table->foreignId('organization_id')->after('id')->nullable()->constrained('organizations')->cascadeOnDelete();
            });
        }

        // 2. Refactor patient_medical_histories
        if (! Schema::hasColumn('patient_medical_histories', 'organization_id')) {
            Schema::table('patient_medical_histories', function (Blueprint $table) {
                $table->foreignId('organization_id')->after('id')->nullable()->constrained('organizations')->cascadeOnDelete();
                if (! Schema::hasColumn('patient_medical_histories', 'medical_alerts')) {
                    $table->json('medical_alerts')->nullable();
                }
                if (! Schema::hasColumn('patient_medical_histories', 'lifestyle_factors')) {
                    $table->json('lifestyle_factors')->nullable();
                }
                if (! Schema::hasColumn('patient_medical_histories', 'chronic_conditions')) {
                    $table->json('chronic_conditions')->nullable();
                }
            });
        }

        // 3. Create generic Patient Timeline Events
        Schema::create('patient_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('event_type'); // registered, appointment_booked, encounter_started, etc.
            $table->json('context')->nullable(); // Metadata about the event
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_timeline_events');

        Schema::table('patient_medical_histories', function (Blueprint $table) {
            $table->dropColumn(['organization_id', 'medical_alerts', 'lifestyle_factors', 'chronic_conditions']);
        });

        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
};
