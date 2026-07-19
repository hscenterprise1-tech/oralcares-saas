<?php

namespace Database\Factories;

use App\Models\CompanyQuotation;
use App\Models\CompanyQuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyQuotationItem>
 */
class CompanyQuotationItemFactory extends Factory
{
    protected $model = CompanyQuotationItem::class;

    public function definition(): array
    {
        $price = fake()->randomFloat(2, 500, 5000);
        $qty = fake()->numberBetween(1, 5);
        $discount = fake()->randomFloat(2, 0, 100);

        return [
            'company_quotation_id' => CompanyQuotation::factory(),
            'title' => fake()->randomElement(['Dental HMS License', 'WhatsApp Setup', 'Additional Branch', 'Premium Support']),
            'description' => fake()->sentence(),
            'quantity' => $qty,
            'unit_price' => $price,
            'discount' => $discount,
            'total' => ($price * $qty) - $discount,
        ];
    }
}
