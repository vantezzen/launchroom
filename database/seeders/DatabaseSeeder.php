<?php

namespace Database\Seeders;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
        $team = Team::factory()->create([
            'name' => 'Launchroom HQ',
            'slug' => 'launchroom-hq',
            'github_token' => encrypt(env('SEED_GITHUB_TOKEN')),
            'github_username' => env('SEED_GITHUB_USERNAME'),
        ]);
        $team->users()->attach($user);

        $project = Project::factory()->create([
            'name' => 'Laravel Demo',
            'slug' => 'laravel-demo',
            'repository' => 'https://github.com/vantezzen/laravel-demo',
            'team_id' => $team->id,
        ]);

        $prodEnvironment = ProjectEnvironment::factory()->create([
            'project_id' => $project->id,
            'domains' => [
                'laravel-demo-production.127.0.0.1.sslip.io',
            ],
            'environment_variables' => [
                'APP_ENV' => 'production',
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_URL' => 'http://laravel-demo-production.127.0.0.1.sslip.io',
            ],
        ]);

        Service::factory()->create([
            'project_environment_id' => $prodEnvironment->id,
            'name' => 'MySQL',
            'category' => 'database',
            'service_type' => 'mysql',
            'environment_variables' => [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => 'mysql',
                'DB_PORT' => 3306,
                'DB_DATABASE' => 'default',
                'DB_USERNAME' => 'app',
                'DB_PASSWORD' => 'password',
            ],
        ]);

        Service::factory()->create([
            'project_environment_id' => $prodEnvironment->id,
            'name' => 'Redis',
            'category' => 'cache',
            'service_type' => 'redis',
            'environment_variables' => [
                'REDIS_HOST' => 'redis',
                'REDIS_PORT' => 6379,
                'REDIS_PASSWORD' => null,
            ],
        ]);

        Deployment::factory()->create([
            'project_environment_id' => $prodEnvironment->id,
        ]);
    }
}
