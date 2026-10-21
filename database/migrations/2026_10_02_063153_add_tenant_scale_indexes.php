<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the query paths that grow with the number of tenants.
 *
 * - The two cross-tenant sweeps `queue:close-day` runs every 15 minutes:
 *   awaiting bookings by status and time, open queues by day. Without them
 *   each run scanned every tenant's appointments.
 * - Tenant-leading lookups that previously had to start from a global index
 *   (`patients.phone`) or scan a parent's rows (queue items by status, a
 *   patient's timeline, a user's notifications and sign-ins, a branch's
 *   bills by status, a clinic's queue history by day).
 */
return new class extends Migration
{
    /**
     * `table => [index name => columns]`.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const INDEXES = [
        'appointments' => ['appointments_status_schedule_index' => ['status', 'scheduled_at', 'branch_id']],
        'clinic_queues' => ['clinic_queues_open_day_index' => ['closed_at', 'queue_date', 'branch_id']],
        'patients' => [
            'patients_clinic_phone_index' => ['clinic_id', 'phone'],
            'patients_clinic_alternate_phone_index' => ['clinic_id', 'alternate_phone'],
        ],
        'clinic_queue_items' => ['clinic_queue_items_queue_status_index' => ['clinic_queue_id', 'status']],
        'patient_timeline_events' => ['patient_timeline_events_patient_created_index' => ['patient_id', 'created_at']],
        'patient_billings' => ['patient_billings_clinic_branch_status_index' => ['clinic_id', 'branch_id', 'status']],
        'ent_notifications' => ['ent_notifications_notifiable_read_index' => ['notifiable_type', 'notifiable_id', 'read_at']],
        'login_histories' => ['login_histories_user_created_index' => ['user_id', 'created_at']],
        'queue_histories' => ['queue_histories_clinic_created_index' => ['clinic_id', 'created_at']],
    ];

    /**
     * Tables whose foreign-key index a composite above can take over, by the
     * foreign-key column it leads with.
     *
     * @var array<string, string>
     */
    private const ABSORBED_FOREIGN_KEYS = [
        'patient_timeline_events' => 'patient_id',
        'patient_billings' => 'clinic_id',
        'login_histories' => 'user_id',
        'queue_histories' => 'clinic_id',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            $this->restoreAbsorbedForeignKeyIndex($table);

            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    /**
     * MySQL silently drops the index it created for a foreign key once another
     * index can serve that key — which a composite index leading with the same
     * column can. Before such a composite is dropped, the key's own index must
     * exist again or MySQL refuses the drop.
     */
    private function restoreAbsorbedForeignKeyIndex(string $table): void
    {
        $foreignKeyColumn = self::ABSORBED_FOREIGN_KEYS[$table] ?? null;
        $indexName = "{$table}_{$foreignKeyColumn}_foreign";

        if (! $foreignKeyColumn
            || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || Schema::hasIndex($table, $indexName)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index([$foreignKeyColumn], $indexName));
    }
};
