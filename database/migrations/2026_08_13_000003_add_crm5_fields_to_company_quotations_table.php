<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('company_quotations', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('company_quotations', 'terms')) {
                $table->text('terms')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('company_quotations', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('valid_until');
            }
            if (! Schema::hasColumn('company_quotations', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('company_quotations', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('company_quotations', 'expired_at')) {
                $table->timestamp('expired_at')->nullable()->after('rejected_at');
            }
            if (! Schema::hasColumn('company_quotations', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_at');
            }
        });

        // Safe organization_id backfill for existing records
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE company_quotations cq
                INNER JOIN leads l ON cq.lead_id = l.id
                SET cq.organization_id = l.organization_id
                WHERE cq.organization_id IS NULL AND cq.lead_id IS NOT NULL
            ');

            DB::statement('
                UPDATE company_quotations cq
                INNER JOIN clinics c ON cq.clinic_id = c.id
                SET cq.organization_id = c.organization_id
                WHERE cq.organization_id IS NULL AND cq.clinic_id IS NOT NULL
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_quotations', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn([
                'organization_id',
                'terms',
                'sent_at',
                'approved_at',
                'rejected_at',
                'expired_at',
                'rejection_reason',
            ]);
        });
    }
};
