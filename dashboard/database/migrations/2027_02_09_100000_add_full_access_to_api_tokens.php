<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Full access" API tokens (D113, docs/16 §1.2, docs/09 R11): a flag, not a
 * list, so a full-access token also gets the abilities added after it was
 * created. Still capped by its holder's role (robots: the team's admin
 * ceiling) and its scope.
 *
 * Backfill: a token becomes full access when its stored abilities are
 * exactly what the "Full access" choice gave its holder's role at one of
 * the points the ability list had (7 abilities, then + run, + fix,
 * + update). Owners and admins (and robots, created by them) got every
 * ability; developers every ability whose permission their role has. A
 * viewer's "Full access" was the same set as its "Deploy" choice, so
 * viewer tokens stay custom, as does every other set. The role is the
 * holder's role today: a member whose role changed since keeps a custom
 * token. The lists are written out here on purpose, as they were.
 */
return new class extends Migration
{
    /** The full ability list over time, oldest first. */
    private const array FULL_SETS = [
        ['read', 'deploy', 'env:read', 'env:write', 'secrets:read', 'domains:write', 'data:transfer'],
        ['read', 'deploy', 'env:read', 'env:write', 'secrets:read', 'domains:write', 'data:transfer', 'run'],
        ['read', 'deploy', 'env:read', 'env:write', 'secrets:read', 'domains:write', 'data:transfer', 'run', 'fix'],
        ['read', 'deploy', 'env:read', 'env:write', 'secrets:read', 'domains:write', 'data:transfer', 'run', 'fix', 'update'],
    ];

    /** The abilities a developer's role could use (no secrets, data transfers or commands). */
    private const array DEVELOPER = ['read', 'deploy', 'env:read', 'env:write', 'domains:write', 'fix', 'update'];

    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->boolean('full_access')->default(false);
        });

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'user')
            ->orderBy('id')
            ->chunkById(500, function ($tokens): void {
                $full = [];

                foreach ($tokens as $token) {
                    $row = (array) $token;

                    if ($this->wasFullAccess($row)) {
                        $full[] = $row['id'];
                    }
                }

                if ($full !== []) {
                    DB::table('personal_access_tokens')->whereIn('id', $full)->update(['full_access' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('full_access');
        });
    }

    /**
     * @param  array<array-key, mixed>  $token
     */
    private function wasFullAccess(array $token): bool
    {
        $stored = is_string($token['abilities'] ?? null) ? json_decode($token['abilities'], true) : null;
        $teamId = $token['team_id'] ?? null;
        $userId = $token['tokenable_id'] ?? null;

        if (! is_array($stored) || ! is_numeric($teamId) || ! is_numeric($userId)) {
            return false;
        }

        $role = $this->role((int) $userId, (int) $teamId);

        if (! in_array($role, ['owner', 'admin', 'developer'], true)) {
            return false;
        }

        $stored = array_values(array_unique(array_filter($stored, is_string(...))));
        sort($stored);

        foreach (self::FULL_SETS as $set) {
            $expected = $role === 'developer' ? array_values(array_intersect($set, self::DEVELOPER)) : $set;
            sort($expected);

            if ($stored === $expected) {
                return true;
            }
        }

        return false;
    }

    /** The holder's role in the token's team: a robot has its team's admin ceiling. */
    private function role(int $userId, int $teamId): ?string
    {
        $robotTeam = DB::table('users')->where('id', $userId)->value('robot_team_id');

        if (is_numeric($robotTeam)) {
            return (int) $robotTeam === $teamId ? 'admin' : null;
        }

        $role = DB::table('team_user')->where('user_id', $userId)->where('team_id', $teamId)->value('role');

        return is_string($role) ? $role : null;
    }
};
