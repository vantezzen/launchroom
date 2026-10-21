<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use Spatie\LaravelData\Data;

final class TeamData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $personal,
        public ?TeamRole $role,
    ) {}

    public static function fromTeam(Team $team, ?TeamRole $role): self
    {
        return new self($team->public_id, $team->name, $team->personal, $role);
    }
}
