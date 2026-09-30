<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Address, contact, and compliance fields a real branch record needs at
 * 100k-clinic scale — added together, once, rather than piecemeal.
 *
 * Two fields deliberately NOT here, because the tables that own them
 * already exist and are wired for exactly this:
 *
 *   - Operating hours: `clinic_operating_hours` (see
 *     `2026_07_20_000048_create_resource_scheduling_tables.php`) already
 *     carries `branch_id`, `day_of_week`, `open_time`, `close_time`,
 *     `is_closed`, and `ScheduleValidationService` already reads it to
 *     validate a booking against a branch's hours. A column here would be a
 *     second, driftable source of truth for the same fact.
 *   - A live chair/room count: the same migration also created `resources`
 *     (`resource_type`: chair/operatory/xray_room/lab_room/custom, scoped to
 *     `branch_id`). `resource_capacity` below is a planning *target*, not a
 *     count of real resource rows — see the column comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Address
            $table->string('address_line1')->nullable()->after('is_main');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city')->nullable()->after('address_line2');
            $table->string('state')->nullable()->after('city');
            $table->string('pincode', 6)->nullable()->after('state');

            // Contact — kept separate: the WhatsApp Business number is
            // frequently not the front-desk landline.
            $table->string('phone')->nullable()->after('pincode');
            $table->string('whatsapp_number')->nullable()->after('phone');

            // Capacity — a planning number ("we're set up for 4 chairs"),
            // not a live tally of `resources` rows. Do not read this as an
            // actual resource count once a Resources screen exists; that
            // screen counts real `resources` rows instead.
            $table->unsignedInteger('resource_capacity')->nullable()->after('whatsapp_number');

            // Compliance — on the branch, not the clinic/organization: GST
            // registration in India is state-wise, and a chain spanning
            // states needs one GSTIN per branch. Named in full
            // (`clinical_establishment_registration_number`, not
            // `registration_number`) to avoid confusion with
            // `doctor_profiles.registration_number`, an unrelated per-doctor
            // professional registration.
            $table->string('gstin')->nullable()->after('resource_capacity');
            $table->string('clinical_establishment_registration_number')->nullable()->after('gstin');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'address_line1',
                'address_line2',
                'city',
                'state',
                'pincode',
                'phone',
                'whatsapp_number',
                'resource_capacity',
                'gstin',
                'clinical_establishment_registration_number',
            ]);
        });
    }
};
