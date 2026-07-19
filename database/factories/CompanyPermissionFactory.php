<?php

namespace Database\Factories;

use App\Models\CompanyPermission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyPermission>
 */
class CompanyPermissionFactory extends Factory
{
    protected $model = CompanyPermission::class;

    public function definition(): array
    {
        $moduleName = fake()->unique()->word();

        return [
            'name' => ucwords($moduleName),
            'key' => Str::slug($moduleName).'.'.fake()->randomElement(['view', 'create', 'update', 'delete']),
            'description' => fake()->sentence(),
            'module' => ucwords($moduleName),
        ];
    }
}
