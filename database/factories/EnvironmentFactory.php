<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => ProjectFactory::new(),
            'name' => 'production',
            'type' => 'production',
            'branch' => 'main',

            'domains' => [fake()->domainName()],
            'environment_variables' => [
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
            ],
        ];
    }
}
