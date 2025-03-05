<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word,
            'project_environment_id' => ProjectEnvironmentFactory::new(),
            'category' => 'database',
            'service_type' => 'mysql',
            'environment_variables' => [
                'DB_HOST' => $this->faker->ipv4,
                'DB_PORT' => $this->faker->numberBetween(3306, 3309),
                'DB_DATABASE' => $this->faker->word,
                'DB_USERNAME' => $this->faker->userName,
                'DB_PASSWORD' => $this->faker->password,
            ],
        ];
    }
}
