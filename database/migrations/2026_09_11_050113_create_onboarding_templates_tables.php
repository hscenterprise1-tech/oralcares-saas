<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What "getting a customer live" means, per kind of customer.
 *
 * The five onboarding steps were written inside the React page — an array of
 * labels a component happened to hold — so the server had no idea what the
 * sequence was, nothing could test it, and every customer got the same one.
 * This product sells to ten kinds of healthcare business: a single dental
 * clinic and a multi-speciality hospital do not go live the same way, and at a
 * hundred and twenty thousand of them "the same five steps for everybody" stops
 * being a simplification and starts being wrong.
 *
 * A template is a named sequence. An organisation resolves to one by its
 * `clinic_type`, falling back to the default template, so an account always has
 * a sequence even when its type is unrecognised or not yet set.
 *
 * `onboarding_progress.completed_steps` keeps storing step keys, so every
 * existing account carries on working against whichever template it resolves
 * to — no backfill, no migration of progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            /*
             * Null means "the fallback". Exactly one row should carry it, which
             * the unique index enforces — MySQL treats nulls as distinct, so a
             * partial-unique cannot be expressed here and `is_default` does the
             * job instead.
             */
            $table->string('clinic_type')->nullable()->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('onboarding_template_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_template_id')->constrained()->cascadeOnDelete();
            /*
             * The key is what `completed_steps` records, so it is stable across
             * a template being renamed or reordered. Unique within a template.
             */
            $table->string('step_key');
            $table->string('label');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position');
            /*
             * A step the customer cannot go live without. Advisory today — the
             * go-live action does not enforce it — but recorded so it can be.
             */
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->unique(['onboarding_template_id', 'step_key']);
            $table->index(['onboarding_template_id', 'position']);
        });

        /*
         * Which template an account is actually following.
         *
         * Resolved from `clinic_type` when a progress row is created, and then
         * held — so changing a template later does not silently rewrite the
         * sequence an account is already half way through.
         */
        Schema::table('onboarding_progress', function (Blueprint $table) {
            $table->foreignId('onboarding_template_id')->nullable()->after('organization_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('onboarding_template_id');
        });

        Schema::dropIfExists('onboarding_template_steps');
        Schema::dropIfExists('onboarding_templates');
    }
};
