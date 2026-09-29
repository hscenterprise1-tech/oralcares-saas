<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two specialty modules, added to the catalogue only.
 *
 * Unlike the 9 core modules seeded in the migration this follows, neither is
 * attached to starter/growth/enterprise here — a dental or physiotherapy
 * plan doesn't exist yet, and guessing which seeded tier should carry a
 * specialty module would be inventing a product decision this migration has
 * no business making. They're picked up from `PackageFormModal`'s checkbox
 * list once a real plan is built around them.
 */
return new class extends Migration
{
    /**
     * @return array<string, array{name: string, description: string}>
     */
    private function catalogue(): array
    {
        return [
            'dental' => ['name' => 'Dental', 'description' => 'Tooth charting, treatment plans and dental procedures.'],
            'physiotherapy' => ['name' => 'Physiotherapy', 'description' => 'Session plans, progress tracking and exercise assignment.'],
        ];
    }

    public function up(): void
    {
        foreach ($this->catalogue() as $key => $attributes) {
            $exists = DB::table('company_features')->where('key', $key)->exists();

            if (! $exists) {
                DB::table('company_features')->insert([
                    'key' => $key,
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Irreversible for the same reason the sibling migration is: a plan
     * created since might already have attached one of these, and deleting
     * the catalogue row out from under it would take a customer's genuinely
     * chosen module with it.
     */
    public function down(): void {}
};
