<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One-time links that let a clinic user set a password: the primary
     * owner's first password (`setup`) and a forgotten one (`reset`). Only a
     * SHA-256 of the random token is stored, so a database read alone cannot
     * produce a working link. `email` is the address the token was issued
     * for; a token stops working if the account's email changes.
     */
    public function up(): void
    {
        Schema::create('clinic_password_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_password_tokens');
    }
};
