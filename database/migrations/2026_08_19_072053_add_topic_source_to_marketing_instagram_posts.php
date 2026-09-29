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
        Schema::table('marketing_instagram_posts', function (Blueprint $table) {
            // 'manual' = user typed a topic (e.g. a festival/occasion); 'auto' = the
            // buffer-maintenance job picked it to keep the ready queue topped up.
            $table->string('topic_source')->default('manual')->after('topic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_instagram_posts', function (Blueprint $table) {
            $table->dropColumn('topic_source');
        });
    }
};
