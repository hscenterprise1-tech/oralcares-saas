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
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
            $table->string('assignment_strategy')->default('round_robin')->after('is_active');
            $table->json('territory_map')->nullable()->after('assignment_strategy');
            $table->unsignedInteger('round_robin_cursor')->default(0)->after('territory_map');

            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['assignment_strategy', 'territory_map', 'round_robin_cursor']);
        });
    }
};
