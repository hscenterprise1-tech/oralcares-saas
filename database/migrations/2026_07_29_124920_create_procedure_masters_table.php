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
        Schema::create('procedure_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_catalog_id')->nullable()->constrained('treatment_catalogs')->nullOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('base_price', 10, 2)->default(0);
            $table->integer('duration_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procedure_masters');
    }
};
