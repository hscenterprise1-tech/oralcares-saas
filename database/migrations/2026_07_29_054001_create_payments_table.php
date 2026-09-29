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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commercial_document_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('transaction_id')->nullable();
            $table->string('gateway')->default('manual');
            $table->string('status')->default('pending'); // pending, successful, failed
            $table->json('metadata')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
