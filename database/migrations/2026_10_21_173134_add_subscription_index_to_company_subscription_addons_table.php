<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on `company_subscription_addons.company_subscription_id`, which
 * every seat and module check reads add-ons by.
 *
 * The table's create migration declares a foreign key on this column, which
 * on MySQL brings its own index — but databases exist where that key (and so
 * the index) is missing. Where an index on the column already exists this
 * adds nothing, and rolling back removes only the index added here. The
 * foreign key itself is not added: that would refuse orphaned rows and start
 * cascading deletes, a separate decision.
 */
return new class extends Migration
{
    private const INDEX = 'co_sub_addons_subscription_idx';

    public function up(): void
    {
        if (Schema::hasIndex('company_subscription_addons', ['company_subscription_id'])) {
            return;
        }

        Schema::table('company_subscription_addons', function (Blueprint $table) {
            $table->index('company_subscription_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('company_subscription_addons', self::INDEX)) {
            return;
        }

        Schema::table('company_subscription_addons', function (Blueprint $table) {
            $table->dropIndex(self::INDEX);
        });
    }
};
