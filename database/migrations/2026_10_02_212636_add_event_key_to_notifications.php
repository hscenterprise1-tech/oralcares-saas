<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient messages are keyed by the event that caused them (e.g.
 * `appointment_reminder:42:202610031000`), unique per clinic, so a retried
 * request can never queue a second message. The dispatcher's index finds what
 * is due without scanning sent history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('event_key', 120)->nullable()->after('notification_template_id');
            $table->unique(['clinic_id', 'event_key'], 'notifications_clinic_event_unique');
            $table->index(['status', 'scheduled_at'], 'notifications_due_index');
        });
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('notifications'));

        // MySQL let the unique index serve the clinic foreign key; give the
        // key its own index back before dropping the unique one.
        Schema::table('notifications', function (Blueprint $table) use ($indexes) {
            if (! $indexes->contains(fn (array $index) => $index['columns'] === ['clinic_id'])) {
                $table->index('clinic_id', 'notifications_clinic_id_foreign');
            }
            if ($indexes->contains(fn (array $index) => $index['name'] === 'notifications_due_index')) {
                $table->dropIndex('notifications_due_index');
            }
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_clinic_event_unique');
            $table->dropColumn('event_key');
        });
    }
};
