<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Finance was missing to report a real number.
 *
 * Three additions, each answering a defect the audit found:
 *
 *   1. `company_subscriptions.mrr` — the recurring value, snapshotted on the
 *      subscription. Revenue used to read a column that did not exist, so
 *      `$subs->sum('mrr')` returned 0 in silence and every figure on the page
 *      was zero. Deriving it live from `company_plans.price` would have been
 *      worse: a price change would rewrite what last year earned.
 *
 *   2. `company_mrr_snapshots` — one row per month. Growth and the trend chart
 *      need history, and there was none, so the chart repeated today's MRR
 *      across six months and presented it as a trend.
 *
 *   3. Expense workflow columns. `company_expenses` had `status` and nothing
 *      to move it with: submitting wrote `approved` in the same request, and
 *      there was no approve action anywhere in the application.
 *
 * Nothing here backfills. The two live subscriptions keep a null `mrr`, and
 * Revenue reports them as unrecorded rather than inventing a figure from
 * today's price list. The five existing expenses keep their exact status,
 * amount and dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_subscriptions', function (Blueprint $table) {
            /*
             * Nullable on purpose, and left null for everything that already
             * exists. A null means "not recorded", which Revenue shows as an
             * exception; a zero would mean "earns nothing", which is a lie.
             */
            $table->decimal('mrr', 12, 2)->nullable()->after('company_plan_id');
            $table->string('mrr_currency', 3)->default('INR')->after('mrr');
        });

        Schema::create('company_mrr_snapshots', function (Blueprint $table) {
            $table->id();
            /* `YYYY-MM`. One snapshot per month, enforced. */
            $table->string('period', 7)->unique();
            $table->decimal('mrr', 14, 2)->default(0);
            $table->unsignedInteger('active_subscriptions')->default(0);
            $table->unsignedInteger('active_clinics')->default(0);
            $table->decimal('arpu', 12, 2)->default(0);
            /*
             * How many active subscriptions had no recorded MRR when this was
             * taken. A snapshot that silently omitted them would understate the
             * month without saying so.
             */
            $table->unsignedInteger('unrecorded_subscriptions')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index('period');
        });

        Schema::table('company_expenses', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('paid_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
            $table->string('payment_reference')->nullable()->after('paid_at');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('company_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['submitted_at', 'approved_at', 'paid_at', 'payment_reference']);
        });

        Schema::dropIfExists('company_mrr_snapshots');

        Schema::table('company_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['mrr', 'mrr_currency']);
        });
    }
};
