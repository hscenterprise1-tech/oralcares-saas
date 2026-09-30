<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The optional add-on catalogue a quotation package can attach — Module A,
 * Module B, Cloud Backup, Support/AMC, and whatever else the business sells
 * alongside a base package.
 *
 * Deliberately not scoped to one `CompanyPlan`: an add-on like "Cloud Backup"
 * applies the same way regardless of which package it rides on, matching how
 * the business actually sells them. A quotation item created from one of
 * these rows snapshots its own title/price at that moment (see
 * `company_quotation_items.company_package_addon_id`) — changing a catalogue
 * price here never rewrites a quotation that already went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_package_addons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            /* A free label for grouping in the picker — 'module', 'service', etc. Not an enum: the catalogue is expected to grow before it needs one. */
            $table->string('type')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            /* 'flat' | 'per_user' | 'per_month' — how `price` should be read when a line item is generated from this add-on. */
            $table->string('pricing_basis')->default('flat');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_package_addons');
    }
};
