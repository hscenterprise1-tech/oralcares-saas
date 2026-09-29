<?php

namespace Database\Factories;

use App\Models\CommercialDocument;
use App\Models\CommercialDocumentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommercialDocumentItem>
 */
class CommercialDocumentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commercial_document_id' => CommercialDocument::factory(),
            'description' => $this->faker->sentence(),
            'quantity' => 1,
            'unit_price' => 100,
            'tax_rate' => 0,
            'total' => 100,
        ];
    }
}
