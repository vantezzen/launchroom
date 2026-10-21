import { useForm } from '@inertiajs/react';
import { Fragment } from 'react';
import InputError from '@/components/input-error';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { update } from '@/routes/notifications/rules';

type Rule = App.Data.Alerts.AlertRuleData;
type Row = {
    rule: App.Enums.Alerts.AlertRule;
    enabled: boolean;
    threshold: number | null;
    duration: number | null;
};

const GROUPS: { key: string; label: string }[] = [
    { key: 'services', label: 'Services' },
    { key: 'servers', label: 'Servers' },
    { key: 'data', label: 'Domains and backups' },
];

function NumberField({
    id,
    value,
    min,
    max,
    label,
    disabled,
    onChange,
}: {
    id: string;
    value: number | null;
    min: number;
    max: number;
    label: string;
    disabled: boolean;
    onChange: (value: number | null) => void;
}) {
    return (
        <Input
            id={id}
            type="number"
            inputMode="numeric"
            min={min}
            max={max}
            aria-label={label}
            disabled={disabled}
            className="h-8 w-16 px-2 text-center tabular-nums"
            value={value ?? ''}
            onChange={(event) =>
                onChange(
                    event.target.value === ''
                        ? null
                        : Number(event.target.value),
                )
            }
        />
    );
}

/**
 * "Above [90] % for [10] min": a rule's threshold as one inline sentence.
 */
function Threshold({
    rule,
    row,
    disabled,
    onChange,
}: {
    rule: Rule;
    row: Row;
    disabled: boolean;
    onChange: (patch: Partial<Row>) => void;
}) {
    if (rule.thresholdUnit === null) {
        return null;
    }

    const threshold = (
        <NumberField
            id={`${rule.rule}-threshold`}
            value={row.threshold}
            min={rule.thresholdMin ?? 0}
            max={rule.thresholdMax ?? 100}
            label={`${rule.label}: threshold`}
            disabled={disabled}
            onChange={(threshold) => onChange({ threshold })}
        />
    );

    return (
        <span className="flex flex-wrap items-center gap-1.5 type-body text-muted-foreground">
            {rule.thresholdUnit === 'days' ? (
                <>Less than {threshold} days left</>
            ) : rule.rule === 'disk_full' ? (
                <>More than {threshold} % full</>
            ) : (
                <>
                    Above {threshold} %
                    {rule.duration !== null && (
                        <>
                            {' '}
                            for
                            <NumberField
                                id={`${rule.rule}-duration`}
                                value={row.duration}
                                min={rule.durationMin ?? 1}
                                max={rule.durationMax ?? 30}
                                label={`${rule.label}: minutes`}
                                disabled={disabled}
                                onChange={(duration) => onChange({ duration })}
                            />
                            min
                        </>
                    )}
                </>
            )}
        </span>
    );
}

/**
 * "What we tell you about" (docs/15 §3.4): every built-in rule as a row
 * with a switch, thresholds as an inline sentence; changes collect in the
 * floating unsaved bar.
 */
export function RulesCard({
    rules,
    canManage,
}: {
    rules: Rule[];
    canManage: boolean;
}) {
    const initial = rules.map((rule): Row => ({
        rule: rule.rule,
        enabled: rule.enabled,
        threshold: rule.threshold,
        duration: rule.duration,
    }));
    const form = useForm<{ rules: Row[] }>({ rules: initial });
    const errors = form.errors as Record<string, string | undefined>;

    const change = (index: number, patch: Partial<Row>) =>
        form.setData(
            'rules',
            form.data.rules.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ),
        );

    return (
        <>
            <SettingsCard
                title="What we tell you about"
                description="Each alert arrives once, and again when it's resolved."
            >
                {GROUPS.map((group) => (
                    <Fragment key={group.key}>
                        <div className="bg-recessed px-5 py-2 type-label text-muted-foreground">
                            {group.label}
                        </div>
                        {rules.map((rule, index) => {
                            if (rule.group !== group.key) {
                                return null;
                            }

                            const row = form.data.rules[index];

                            return (
                                <SettingsRow
                                    key={rule.rule}
                                    label={rule.label}
                                    htmlFor={`${rule.rule}-enabled`}
                                    description={
                                        <span className="flex flex-col gap-2">
                                            <span>{rule.description}</span>
                                            {row.enabled && (
                                                <Threshold
                                                    rule={rule}
                                                    row={row}
                                                    disabled={!canManage}
                                                    onChange={(patch) =>
                                                        change(index, patch)
                                                    }
                                                />
                                            )}
                                            <InputError
                                                message={
                                                    errors[
                                                        `rules.${index}.threshold`
                                                    ] ??
                                                    errors[
                                                        `rules.${index}.duration`
                                                    ]
                                                }
                                            />
                                        </span>
                                    }
                                    align="start"
                                    inline
                                >
                                    <Switch
                                        id={`${rule.rule}-enabled`}
                                        checked={row.enabled}
                                        disabled={!canManage}
                                        onCheckedChange={(enabled) =>
                                            change(index, { enabled })
                                        }
                                    />
                                </SettingsRow>
                            );
                        })}
                    </Fragment>
                ))}
            </SettingsCard>
            <UnsavedBar
                open={form.isDirty}
                saving={form.processing}
                onReset={() => form.reset()}
                onSave={() =>
                    form.put(update().url, {
                        preserveScroll: true,
                        onSuccess: () => form.setDefaults(),
                    })
                }
            />
        </>
    );
}
