<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Teams\CreateTeam;
use App\Data\Audit\AuditActor;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Dev data: an instance owner (test@example.com / password) with a
     * personal team. Owners must set up 2FA on first login (docs/09 R10).
     */
    public function run(CreateTeam $createTeam): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
        $user->forceFill(['is_instance_owner' => true])->save();

        $createTeam->handle($user, 'Test’s team', true, AuditActor::system());
    }
}
