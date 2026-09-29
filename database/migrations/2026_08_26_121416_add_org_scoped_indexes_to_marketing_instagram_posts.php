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
        // The content queue's two hot queries — the post-history list
        // (organization + newest first) and the ready-buffer count
        // (organization + status IN (...)) — both did a full table scan
        // (confirmed via EXPLAIN) despite single-column indexes existing on
        // organization_id and status separately; neither is selective
        // enough alone once posts accumulate.
        Schema::table('marketing_instagram_posts', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'mkt_ig_post_org_created_idx');
            $table->index(['organization_id', 'status'], 'mkt_ig_post_org_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketing_instagram_posts', function (Blueprint $table) {
            $table->dropIndex('mkt_ig_post_org_created_idx');
            $table->dropIndex('mkt_ig_post_org_status_idx');
        });
    }
};
