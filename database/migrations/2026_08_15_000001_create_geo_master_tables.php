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
        // 1. Countries
        Schema::create('geo_countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name');
            $table->string('phone_code', 10)->nullable();
            $table->string('currency_code', 3)->default('INR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });

        // 2. States / Union Territories
        Schema::create('geo_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('geo_countries')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->string('gst_code', 2)->nullable();
            $table->string('type', 20)->default('state'); // 'state' | 'union_territory'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->index(['country_id', 'name']);
            $table->index('is_active');
        });

        // 3. Districts
        Schema::create('geo_districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained('geo_states')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['state_id', 'name']);
            $table->index(['state_id', 'name']);
            $table->index('is_active');
        });

        // 4. Cities / Urban Agglomerations
        Schema::create('geo_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('geo_districts')->cascadeOnDelete();
            $table->foreignId('state_id')->constrained('geo_states')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->string('tier', 20)->default('tier_2'); // 'tier_1' | 'tier_2' | 'tier_3'
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['district_id', 'name']);
            $table->index(['state_id', 'name']);
            $table->index('slug');
            $table->index('tier');
            $table->index('is_active');
        });

        // 5. Areas / Localities
        Schema::create('geo_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('geo_cities')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'name']);
            $table->index(['city_id', 'name']);
            $table->index('slug');
            $table->index('is_active');
        });

        // 6. Pincodes
        Schema::create('geo_pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 6);
            $table->foreignId('area_id')->nullable()->constrained('geo_areas')->nullOnDelete();
            $table->foreignId('city_id')->constrained('geo_cities')->cascadeOnDelete();
            $table->foreignId('district_id')->constrained('geo_districts')->cascadeOnDelete();
            $table->foreignId('state_id')->constrained('geo_states')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('pincode');
            $table->index(['city_id', 'pincode']);
            $table->index(['state_id', 'pincode']);
            $table->index(['area_id', 'pincode']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geo_pincodes');
        Schema::dropIfExists('geo_areas');
        Schema::dropIfExists('geo_cities');
        Schema::dropIfExists('geo_districts');
        Schema::dropIfExists('geo_states');
        Schema::dropIfExists('geo_countries');
    }
};
