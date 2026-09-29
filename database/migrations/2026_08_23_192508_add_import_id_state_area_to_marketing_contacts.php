<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add import batch traceability and geographic hierarchy to marketing contacts.
     *
     * - import_id: links each contact to the import batch that created them.
     *   NULL for manually-created contacts. SET NULL on batch deletion so contacts
     *   are not lost if the import record is removed.
     * - state: top-level geographic filter (e.g. "Gujarat")
     * - area: sub-city geographic filter (e.g. "Alkapuri")
     */
    public function up(): void
    {
        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->foreignId('import_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('marketing_contact_imports')
                ->nullOnDelete();

            $table->string('state')->nullable()->after('city')->index();
            $table->string('area')->nullable()->after('state')->index();

            $table->index('import_id', 'mc_import_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->dropForeign(['import_id']);
            $table->dropIndex('mc_import_id_idx');
            $table->dropColumn(['import_id', 'state', 'area']);
        });
    }
};
