<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deployment>
 */
class DeploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => EnvironmentFactory::new(),
            'commit_hash' => fake()->sha256(),
            'status' => 'succeeded',
            'output' => fake()->sentence(),
            'is_latest' => true,

            'started_at' => fake()->dateTime(),
            'finished_at' => now(),
        ];
    }
}
