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
        Schema::table('trials', function (Blueprint $table) {
            if (! Schema::hasColumn('trials', 'plan')) {
                $table->string('plan')->default('Basic Trial Plan')->after('lead_id');
            }
            if (! Schema::hasColumn('trials', 'duration_days')) {
                $table->integer('duration_days')->default(14)->after('plan');
            }
            if (! Schema::hasColumn('trials', 'assigned_user_id')) {
                $table->foreignId('assigned_user_id')->nullable()->after('duration_days')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('trials', 'onboarding_checklist')) {
                $table->json('onboarding_checklist')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('trials', 'outcome')) {
                $table->string('outcome')->nullable()->after('onboarding_checklist');
            }
            if (! Schema::hasColumn('trials', 'feedback')) {
                $table->text('feedback')->nullable()->after('outcome');
            }
            if (! Schema::hasColumn('trials', 'objections')) {
                $table->text('objections')->nullable()->after('feedback');
            }
            if (! Schema::hasColumn('trials', 'requested_features')) {
                $table->text('requested_features')->nullable()->after('objections');
            }
            if (! Schema::hasColumn('trials', 'next_action')) {
                $table->text('next_action')->nullable()->after('requested_features');
            }
            if (! Schema::hasColumn('trials', 'extension_count')) {
                $table->integer('extension_count')->default(0)->after('next_action');
            }
            if (! Schema::hasColumn('trials', 'original_end_date')) {
                $table->date('original_end_date')->nullable()->after('extension_count');
            }
            if (! Schema::hasColumn('trials', 'extended_at')) {
                $table->timestamp('extended_at')->nullable()->after('original_end_date');
            }
            if (! Schema::hasColumn('trials', 'extension_reason')) {
                $table->text('extension_reason')->nullable()->after('extended_at');
            }
            if (! Schema::hasColumn('trials', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('extension_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trials', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropColumn([
                'plan',
                'duration_days',
                'assigned_user_id',
                'onboarding_checklist',
                'outcome',
                'feedback',
                'objections',
                'requested_features',
                'next_action',
                'extension_count',
                'original_end_date',
                'extended_at',
                'extension_reason',
                'completed_at',
            ]);
        });
    }
};
