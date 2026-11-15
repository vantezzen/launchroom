import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';

type Ability = App.Enums.ApiAbility;
type TeamOption = App.Data.Api.ApiTeamOptionData;

/** "30 days", "1 year". */
export function lifetimeLabel(days: number): string {
    return days === 365 ? '1 year' : `${days} days`;
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

/** The ability checkboxes of a token (settings dialog, CLI approval). */
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
    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-3 type-label">{legend}</legend>
            {(team?.abilities ?? []).map((option) => {
                const id = `${idPrefix}-${option.value}`;
                const always = option.locked;

                return (
                    <div key={option.value} className="flex items-start gap-3">
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
                        <label htmlFor={id} className="flex flex-col gap-0.5">
                            <span className="type-body font-medium">
                                {option.label}
                                {explainUnavailable && !option.available && (
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
