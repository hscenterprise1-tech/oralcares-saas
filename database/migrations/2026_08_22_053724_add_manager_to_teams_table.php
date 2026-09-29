<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sales team needs an accountable owner.
 *
 * Assignment strategy answered "who gets the next lead"; nothing answered
 * "who is responsible for this team's number", which the Sales Teams module
 * needs in order to report workload and performance against a manager.
 *
 * Nulled rather than cascaded on delete: losing a manager's user record must
 * not silently delete the team and its membership history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->after('description')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }
};
