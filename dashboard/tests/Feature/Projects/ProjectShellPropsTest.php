<?php

declare(strict_types=1);

use App\Data\Canvas\ProjectShellPropsData;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Queries\ProjectShell;
use Inertia\DeferProp;
use Inertia\OptionalProp;
use Tests\Support\Projects;

test('the project shell props are exactly ProjectShellPropsData', function (): void {
    $team = Team::factory()->create();
    $this->actingAs(memberOf($team, TeamRole::Developer));
    $project = Projects::create($team);

    $shared = ProjectShell::props($project, null);
    $resolved = array_map(fn (mixed $value): mixed => $value instanceof Closure || $value instanceof OptionalProp || $value instanceof DeferProp ? $value() : $value, $shared);

    $properties = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        new ReflectionMethod(ProjectShellPropsData::class, '__construct')->getParameters(),
    );

    expect(array_keys($resolved))->toEqualCanonicalizing($properties)
        ->and(ProjectShellPropsData::from($resolved))->toBeInstanceOf(ProjectShellPropsData::class);
});
