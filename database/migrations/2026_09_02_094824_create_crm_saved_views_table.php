<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own cuts of the Deals list.
 *
 * The eight built-in views are defined in code, because they mean the same
 * thing for everybody and must never drift. This table holds only the ones a
 * person names for themselves — "Mumbai enterprise, over 5 lakh, no owner" —
 * which is a query nobody else needs and which would be re-typed daily
 * otherwise.
 *
 * `filters` stores the query string as it already travels: the same keys the
 * list reads from the URL, so a saved view is nothing more than a bookmark the
 * product remembers. That keeps the table dumb — no column ever has to learn
 * about a new filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_saved_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Carried so a tenant's views can never leak across a boundary
             * even if a user is later moved between organizations.
             */
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->json('filters');

            $table->timestamps();

            // One name per person; saving over a name replaces that view.
            $table->unique(['user_id', 'name']);
            $table->index(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saved_views');
    }
};
