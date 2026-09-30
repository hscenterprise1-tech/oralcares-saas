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
        Schema::table('marketing_instagram_accounts', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('username');
            $table->unsignedTinyInteger('buffer_target')->default(10)->after('logo_path');
            $table->boolean('auto_fill_enabled')->default(true)->after('buffer_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_instagram_accounts', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'buffer_target', 'auto_fill_enabled']);
        });
    }
};
