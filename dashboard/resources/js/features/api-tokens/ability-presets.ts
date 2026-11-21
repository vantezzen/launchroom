type Ability = App.Enums.ApiAbility;
type TeamOption = App.Data.Api.ApiTeamOptionData;

/**
 * The access choices above the ability list; `custom` shows the boxes.
 * `full` is a setting of its own (D113): the token gets every ability its
 * role allows, including ones launchroom adds later.
 */
export type AccessPreset = 'deploy' | 'read' | 'full' | 'custom';

const DEFAULTS: Ability[] = ['read', 'deploy', 'env:read', 'env:write', 'fix'];
/** The defaults before `fix` joined them (D111): those tokens still read "Deploy". */
const FORMER_DEFAULTS: Ability[] = ['read', 'deploy', 'env:read', 'env:write'];

function sameSet(a: Ability[], b: Ability[]): boolean {
    const left = new Set(a);
    const right = new Set(b);

    return left.size === right.size && [...left].every((x) => right.has(x));
}

/**
 * The abilities a preset grants in `team` today (never more than the role
 * allows). For `full` that is only today's list: the token itself carries
 * the flag.
 */
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

            return preset === 'full' || (preset === 'deploy' && option.default);
        })
        .map((option) => option.value);
}

/**
 * The presets worth offering in `team`: Deploy only when it grants more
 * than Read only. Full access is always offered, it differs by including
 * later permissions.
 */
export function offeredPresets(
    team: TeamOption | null | undefined,
): AccessPreset[] {
    const read = presetAbilities(team, 'read');
    const deploy = presetAbilities(team, 'deploy');
    const offered: AccessPreset[] = [];

    if (!sameSet(deploy, read)) {
        offered.push('deploy');
    }

    offered.push('read', 'full', 'custom');

    return offered;
}

/** Which preset a token's access is in `team`, else custom. */
export function presetOf(
    team: TeamOption | null | undefined,
    abilities: Ability[],
    fullAccess = false,
): AccessPreset {
    if (fullAccess) {
        return 'full';
    }

    const offered = offeredPresets(team);

    for (const preset of ['deploy', 'read'] as const) {
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
 * One phrase for a token's access (token lists): "Full access" for the
 * flag, "Read only", "Deploy" (the defaults), else "Custom" with the
 * count. A custom token that happens to have every ability today stays
 * custom: it won't get later ones.
 */
export function abilitySummary(token: {
    abilities: Ability[];
    fullAccess: boolean;
}): { label: string; custom: boolean } {
    const { abilities } = token;

    if (token.fullAccess) {
        return { label: 'Full access', custom: false };
    }

    if (sameSet(abilities, ['read'])) {
        return { label: 'Read only', custom: false };
    }

    if (sameSet(abilities, DEFAULTS) || sameSet(abilities, FORMER_DEFAULTS)) {
        return { label: 'Deploy', custom: false };
    }

    const count = abilities.length;

    return {
        label: `Custom, ${count} ${count === 1 ? 'permission' : 'permissions'}`,
        custom: true,
    };
}
