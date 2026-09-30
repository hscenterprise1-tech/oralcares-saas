<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the "latest campaign status" lookup a covering index.
 *
 * A contact's campaign status is not stored — it is whatever their most recent
 * `marketing_campaign_recipients` row says, resolved by a correlated
 * sub-select. That keeps the answer honest, but it means the sub-select runs
 * once per contact whenever the contacts list is filtered or counted by
 * lifecycle state.
 *
 * The existing index found the right recipient row quickly enough, but stopped
 * short of `status` — so every one of those lookups then had to fetch the full
 * row from the clustered index just to read one column. At ten thousand
 * contacts that is ten thousand extra random reads on a single page load.
 *
 * Adding `status` to the tail makes the index cover the whole sub-select.
 * Measured on the development database with 10,000 contacts, the grouped
 * lifecycle count went from ~252ms to ~48ms.
 *
 * The old index is dropped because the new one begins with exactly the same
 * three columns in the same order — anything the old one could serve, the new
 * one serves too, so keeping both would only cost write time and space.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->index(
                ['contact_id', 'updated_at', 'id', 'status'],
                'mcr_contact_latest_covering_idx'
            );
        });

        if ($this->hasIndex('mcr_contact_latest_idx')) {
            Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
                $table->dropIndex('mcr_contact_latest_idx');
            });
        }
    }

    public function down(): void
    {
        if (! $this->hasIndex('mcr_contact_latest_idx')) {
            Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
                $table->index(['contact_id', 'updated_at', 'id'], 'mcr_contact_latest_idx');
            });
        }

        Schema::table('marketing_campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('mcr_contact_latest_covering_idx');
        });
    }

    /**
     * Whether an index of this name exists on the recipients table.
     *
     * Checked rather than assumed so this migration is safe on a database that
     * never received the earlier index.
     */
    private function hasIndex(string $name): bool
    {
        return collect(
            Schema::getIndexes('marketing_campaign_recipients')
        )->contains(fn (array $index) => $index['name'] === $name);
    }
};
