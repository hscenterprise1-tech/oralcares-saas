<?php

namespace Database\Factories;

use App\Models\CommercialDocument;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialDocument>
 */
class CommercialDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'type' => 'quotation',
            'document_number' => 'Q-'.$this->faker->unique()->numberBetween(1000, 9999),
            'version' => 1,
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal' => 1000,
            'tax_amount' => 100,
            'discount_amount' => 0,
            'grand_total' => 1100,
            'valid_until' => now()->addDays(30)->toDateString(),
            'metadata' => [
                'snapshot' => [
                    'pricing_frozen' => true,
                ],
            ],
            'created_by' => User::factory(),
        ];
    }
}
