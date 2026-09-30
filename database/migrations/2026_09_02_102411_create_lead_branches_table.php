<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branches a prospect already runs.
 *
 * Deliberately not the `branches` table. That one hangs off `clinic_id` and is
 * created by CustomerProvisioningEngine when a deal is won — it describes
 * branches we host. This describes branches the prospect operates today, which
 * a rep records while qualifying, long before any clinic record exists.
 *
 * Keeping them apart means winning a deal can still create real branches from
 * these without either table having to pretend to be the other, and a lost
 * deal's branch notes never leak into the hosted product.
 *
 * `leads.branch_count` stays as the headline number a rep can give before they
 * have the details; these rows are the details when they have them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_branches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            /*
             * Exactly one branch per lead should carry this. It is enforced in
             * the model rather than by a partial unique index, which MySQL
             * does not support and which would make an ordinary two-branch
             * edit awkward for no safety gained.
             */
            $table->boolean('is_main')->default(false);

            $table->string('address')->nullable();
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->string('contact_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['lead_id', 'is_main']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_branches');
    }
};
