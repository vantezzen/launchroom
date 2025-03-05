<?php

namespace Database\Seeders;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvironment;
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
        ]);
        $team->users()->attach($user);

        $project = Project::factory()->create([
            'name' => 'Launchroom',
            'slug' => 'launchroom',
            'repository' => 'https://github.com/vantezzen/launchroom',
            'team_id' => $team->id,
        ]);
        
        $prodEnvironment = ProjectEnvironment::factory()->create([
            'project_id' => $project->id,
        ]);

        Deployment::factory()->create([
            'project_environment_id' => $prodEnvironment->id,
        ]);
    }
}
