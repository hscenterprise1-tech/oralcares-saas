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
        Schema::create('commercial_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // quotation, agreement, invoice, credit_note, purchase_order
            $table->string('document_number')->index();
            $table->integer('version')->default(1);
            $table->string('status')->default('draft'); // draft, under_review, approved, sent, viewed, accepted, rejected, expired, archived
            $table->string('currency', 3)->default('USD');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->json('metadata')->nullable(); // Extension point & Commercial Snapshot
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('commercial_documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_documents');
    }
};
