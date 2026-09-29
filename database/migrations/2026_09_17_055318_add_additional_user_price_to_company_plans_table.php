<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-user rate for seats beyond a plan's own `max_users`. Nullable: a
 * plan that does not sell extra seats simply has none, rather than a zero
 * that would let a quotation add unlimited free users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_plans', function (Blueprint $table) {
            $table->decimal('additional_user_price', 15, 2)->nullable()->after('max_users');
        });
    }

    public function down(): void
    {
        Schema::table('company_plans', function (Blueprint $table) {
            $table->dropColumn('additional_user_price');
        });
    }
};
