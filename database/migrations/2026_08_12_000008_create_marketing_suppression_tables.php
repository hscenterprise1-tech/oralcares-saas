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
        // 1. Add suppression metadata to marketing_contacts table
        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->string('suppression_reason')->nullable()->after('marketing_status');
            $table->string('suppression_source')->nullable()->after('suppression_reason');
            $table->timestamp('status_changed_at')->nullable()->after('suppression_source');
        });

        // 2. Create single compliance audit log table
        Schema::create('marketing_contact_suppression_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('marketing_contacts')->cascadeOnDelete();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('reason');
            $table->string('source');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for fast compliance querying & tenant isolation
            $table->index(['organization_id', 'contact_id'], 'mkt_sup_log_org_contact_idx');
            $table->index(['organization_id', 'new_status'], 'mkt_sup_log_org_status_idx');
            $table->index(['organization_id', 'reason'], 'mkt_sup_log_org_reason_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketing_contact_suppression_logs');

        Schema::table('marketing_contacts', function (Blueprint $table) {
            $table->dropColumn(['suppression_reason', 'suppression_source', 'status_changed_at']);
        });
    }
};
