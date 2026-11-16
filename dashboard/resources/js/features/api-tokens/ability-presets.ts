type Ability = App.Enums.ApiAbility;
type TeamOption = App.Data.Api.ApiTeamOptionData;

/** The access choices above the ability list; `custom` shows the boxes. */
export type AccessPreset = 'deploy' | 'read' | 'everything' | 'custom';

/** Every ability, in the enum's order (the fallback without a team). */
const ALL: Ability[] = [
    'read',
    'deploy',
    'env:read',
    'env:write',
    'secrets:read',
    'domains:write',
    'data:transfer',
];
const DEFAULTS: Ability[] = ['read', 'deploy', 'env:read', 'env:write'];

function sameSet(a: Ability[], b: Ability[]): boolean {
    const left = new Set(a);
    const right = new Set(b);

    return left.size === right.size && [...left].every((x) => right.has(x));
}

/** The abilities a preset grants in `team` (never more than the role allows). */
export function presetAbilities(
    team: TeamOption | null | undefined,
    preset: Exclude<AccessPreset, 'custom'>,
): Ability[] {
    const options = team?.abilities ?? [];

    return options
        .filter((option) => {
            if (option.locked) {
                return true;
            }

            if (!option.available) {
                return false;
            }

            return (
                preset === 'everything' ||
                (preset === 'deploy' && option.default)
            );
        })
        .map((option) => option.value);
}

/**
 * The presets worth offering in `team`: one that grants the same as a
 * cheaper one is left out (a viewer only gets Read only and Custom).
 */
export function offeredPresets(
    team: TeamOption | null | undefined,
): AccessPreset[] {
    const read = presetAbilities(team, 'read');
    const deploy = presetAbilities(team, 'deploy');
    const everything = presetAbilities(team, 'everything');
    const offered: AccessPreset[] = [];

    if (!sameSet(deploy, read)) {
        offered.push('deploy');
    }

    offered.push('read');

    if (!sameSet(everything, deploy) && !sameSet(everything, read)) {
        offered.push('everything');
    }

    offered.push('custom');

    return offered;
}

/** Which preset `abilities` is in `team`, else custom. */
export function presetOf(
    team: TeamOption | null | undefined,
    abilities: Ability[],
): AccessPreset {
    const offered = offeredPresets(team);

    for (const preset of ['deploy', 'read', 'everything'] as const) {
        if (
            offered.includes(preset) &&
            sameSet(presetAbilities(team, preset), withLocked(team, abilities))
        ) {
            return preset;
        }
    }

    return 'custom';
}

function withLocked(
    team: TeamOption | null | undefined,
    abilities: Ability[],
): Ability[] {
    const locked = (team?.abilities ?? [])
        .filter((option) => option.locked)
        .map((option) => option.value);

    return [...new Set([...locked, ...abilities])];
}

/**
 * One phrase for a token's access (Settings → API tokens): "Full access"
 * (everything its owner's role allows in the team), "Deploy" (the
 * defaults), "Read only", else "Custom" with the count.
 */
export function abilitySummary(
    abilities: Ability[],
    team?: TeamOption | null,
): { label: string; custom: boolean } {
    const everything = team ? presetAbilities(team, 'everything') : ALL;

    if (sameSet(abilities, ['read'])) {
        return { label: 'Read only', custom: false };
    }

    if (sameSet(abilities, everything)) {
        return { label: 'Full access', custom: false };
    }

    if (sameSet(abilities, DEFAULTS)) {
        return { label: 'Deploy', custom: false };
    }

    const count = abilities.length;

    return {
        label: `Custom, ${count} ${count === 1 ? 'permission' : 'permissions'}`,
        custom: true,
    };
}
