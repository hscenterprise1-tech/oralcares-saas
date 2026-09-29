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
        Schema::create('company_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();
            $table->string('description');
            $table->string('category')->default('office'); // software, infrastructure, marketing, office, travel, miscellaneous
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_expenses');
    }
};
