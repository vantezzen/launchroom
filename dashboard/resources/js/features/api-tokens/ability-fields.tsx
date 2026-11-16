import { useState } from 'react';
import type { ReactNode } from 'react';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { Checkbox } from '@/components/ui/checkbox';
import {
    offeredPresets,
    presetAbilities,
    presetOf,
} from '@/features/api-tokens/ability-presets';
import type { AccessPreset } from '@/features/api-tokens/ability-presets';

type Ability = App.Enums.ApiAbility;
type TeamOption = App.Data.Api.ApiTeamOptionData;

export { abilitySummary } from '@/features/api-tokens/ability-presets';

/** "30 days", "1 year". */
export function lifetimeLabel(days: number): string {
    return days === 365 ? '1 year' : `${days} days`;
}

/** "Until Feb 14, 2027": when a token created now with this lifetime stops working. */
export function lifetimeEnd(days: number, now: Date = new Date()): string {
    const end = new Date(now.getTime() + days * 24 * 60 * 60 * 1000);

    return `Until ${end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`;
}

/** The abilities a new token in `team` starts with. */
export function defaultAbilities(
    team: TeamOption | null | undefined,
): Ability[] {
    return (team?.abilities ?? [])
        .filter((option) => option.available && option.default)
        .map((option) => option.value);
}

function toggleAbility(
    current: Ability[],
    ability: Ability,
    on: boolean,
): Ability[] {
    return on
        ? [...current, ability]
        : current.filter((entry) => entry !== ability);
}

const PRESET_LABEL: Record<AccessPreset, string> = {
    deploy: 'Deploy',
    read: 'Read only',
    everything: 'Everything',
    custom: 'Custom',
};

function presetSentence(
    team: TeamOption | null | undefined,
    preset: AccessPreset,
): string | null {
    if (preset === 'read') {
        return 'Can see projects, deployments, logs and servers, and change nothing.';
    }

    if (preset === 'deploy') {
        return 'Can deploy and change variables. Can’t read secret values.';
    }

    if (preset === 'everything') {
        const all = presetAbilities(team, 'everything');

        return all.includes('secrets:read') || all.includes('data:transfer')
            ? 'Everything your role allows. Secret reads and data transfers are audited.'
            : 'Everything your role allows.';
    }

    return null;
}

/**
 * A token's access (settings dialog, CLI approval): presets first (Deploy,
 * Read only, Everything), the ability boxes only under Custom. Abilities
 * the role can't grant stay disabled.
 */
export function AbilityFields({
    legend,
    idPrefix,
    team,
    value,
    onChange,
    explainUnavailable = false,
    children,
}: {
    legend: string;
    idPrefix: string;
    team: TeamOption | null | undefined;
    value: Ability[];
    onChange: (abilities: Ability[]) => void;
    /** Says why a box is disabled ("not available with your role"). */
    explainUnavailable?: boolean;
    /** Under the boxes (validation errors). */
    children?: ReactNode;
}) {
    const [preset, setPreset] = useState<AccessPreset>(() =>
        presetOf(team, value),
    );
    const choose = (next: AccessPreset) => {
        setPreset(next);

        if (next !== 'custom') {
            onChange(presetAbilities(team, next));
        }
    };

    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-2 type-label">{legend}</legend>
            <TimeRangeSelect<AccessPreset>
                label={legend}
                value={preset}
                onChange={choose}
                options={offeredPresets(team).map((option) => ({
                    value: option,
                    label: PRESET_LABEL[option],
                }))}
                className="self-start"
            />
            {preset !== 'custom' && (
                <p className="type-small text-muted-foreground">
                    {presetSentence(team, preset)}
                </p>
            )}
            {preset === 'custom' &&
                (team?.abilities ?? []).map((option) => {
                    const id = `${idPrefix}-${option.value}`;
                    const always = option.locked;

                    return (
                        <div
                            key={option.value}
                            className="flex items-start gap-3"
                        >
                            <Checkbox
                                id={id}
                                checked={always || value.includes(option.value)}
                                disabled={always || !option.available}
                                onCheckedChange={(checked) =>
                                    onChange(
                                        toggleAbility(
                                            value,
                                            option.value,
                                            checked === true,
                                        ),
                                    )
                                }
                                className="mt-0.5"
                            />
                            <label
                                htmlFor={id}
                                className="flex flex-col gap-0.5"
                            >
                                <span className="type-body font-medium">
                                    {option.label}
                                    {explainUnavailable &&
                                        !option.available && (
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                (not available with your role)
                                            </span>
                                        )}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    {option.description}
                                </span>
                            </label>
                        </div>
                    );
                })}
            {children}
        </fieldset>
    );
}
