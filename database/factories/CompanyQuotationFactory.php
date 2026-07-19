<?php

namespace Database\Factories;

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\QuotationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyQuotation>
 */
class CompanyQuotationFactory extends Factory
{
    protected $model = CompanyQuotation::class;

    public function definition(): array
    {
        return [
            'quotation_number' => 'QT-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'version' => 1,
            'parent_id' => null,
            'lead_id' => null,
            'clinic_id' => null,
            'prepared_by_id' => CompanyEmployee::factory(),
            'assigned_id' => null,
            'status' => QuotationStatus::Draft,
            'valid_until' => now()->addDays(30),
            'subtotal' => 0.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'grand_total' => 0.00,
            'notes' => fake()->paragraph(),
        ];
    }
}
