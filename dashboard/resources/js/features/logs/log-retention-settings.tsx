import { router } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '@/components/patterns/empty-state';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { logRetention } from '@/routes/projects';

type Retention = App.Data.Logs.EnvironmentLogRetentionData;
type Options = { days: number[]; maxMb: number[] };

function days(n: number): string {
    return n === 1 ? '1 day' : `${n} days`;
}

function size(mb: number): string {
    return mb >= 1024 ? `${mb / 1024} GB` : `${mb} MB`;
}

function stored(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    if (bytes < 1024 * 1024 * 1024) {
        return `${Math.round(bytes / (1024 * 1024))} MB`;
    }

    return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

const dateFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
});

/**
 * One environment: keep its runtime logs (opt-in), for how long, up to how
 * much. Saved with the card's Save; applies to running services without a
 * restart.
 */
function EnvironmentRetention({
    project,
    environment,
    options,
}: {
    project: string;
    environment: Retention;
    options: Options;
}) {
    const [enabled, setEnabled] = useState(environment.enabled);
    const [keepDays, setKeepDays] = useState(environment.days);
    const [maxMb, setMaxMb] = useState(environment.maxMb);
    const [processing, setProcessing] = useState(false);
    const dirty =
        enabled !== environment.enabled ||
        (enabled &&
            (keepDays !== environment.days || maxMb !== environment.maxMb));
    const disabled = !environment.canUpdate;

    const save = () => {
        setProcessing(true);
        router.patch(
            logRetention({ project, environment: environment.id }).url,
            { enabled, days: keepDays, maxMb },
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    const footer =
        environment.storedBytes > 0
            ? `${stored(environment.storedBytes)} of runtime logs stored${environment.oldestAt ? ` since ${dateFormat.format(new Date(environment.oldestAt))}` : ''}.`
            : environment.enabled
              ? 'Nothing stored yet. Lines arrive within seconds of being written.'
              : 'Without it, runtime logs are shown live and kept for a day.';

    return (
        <SettingsCard
            title={environment.name}
            footer={
                <div className="flex items-center justify-between gap-3">
                    <span>{footer}</span>
                    {!disabled && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!dirty || processing}
                            onClick={save}
                        >
                            Save
                        </Button>
                    )}
                </div>
            }
        >
            <SettingsRow
                label="Keep runtime logs"
                description="Every line your services write, searchable from the Logs tab."
                htmlFor={`retain-${environment.id}`}
                inline
            >
                <Switch
                    id={`retain-${environment.id}`}
                    checked={enabled}
                    disabled={disabled}
                    onCheckedChange={setEnabled}
                />
            </SettingsRow>
            {enabled && (
                <>
                    <SettingsRow
                        label="Keep for"
                        description="Older lines are deleted."
                        htmlFor={`days-${environment.id}`}
                    >
                        <Select
                            value={String(keepDays)}
                            disabled={disabled}
                            onValueChange={(value) =>
                                setKeepDays(Number(value))
                            }
                        >
                            <SelectTrigger
                                id={`days-${environment.id}`}
                                className="w-full sm:w-40"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.days.map((option) => (
                                    <SelectItem
                                        key={option}
                                        value={String(option)}
                                    >
                                        {days(option)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </SettingsRow>
                    <SettingsRow
                        label="Up to"
                        description="When it's full, the oldest lines go first."
                        htmlFor={`size-${environment.id}`}
                    >
                        <Select
                            value={String(maxMb)}
                            disabled={disabled}
                            onValueChange={(value) => setMaxMb(Number(value))}
                        >
                            <SelectTrigger
                                id={`size-${environment.id}`}
                                className="w-full sm:w-40"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {options.maxMb.map((option) => (
                                    <SelectItem
                                        key={option}
                                        value={String(option)}
                                    >
                                        {size(option)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </SettingsRow>
                </>
            )}
        </SettingsCard>
    );
}

/**
 * Project settings → Logs (docs/08 §12): runtime log retention per
 * environment. Build and deploy logs are always kept with their
 * deployments; previews keep live lines only.
 */
export function LogRetentionSettings({
    project,
    environments,
    options,
}: {
    project: string;
    environments: Retention[];
    options: Options;
}) {
    if (environments.length === 0) {
        return <EmptyState variant="plain">No environments yet.</EmptyState>;
    }

    return (
        <div className="flex flex-col gap-10">
            <p className="max-w-prose type-body text-muted-foreground">
                Build and deploy logs are kept with their deployments. Runtime
                logs are shown live; keep them to search them later. They stay
                on this dashboard's server, inside its database.
            </p>
            {environments.map((environment) => (
                <EnvironmentRetention
                    key={environment.id}
                    project={project}
                    environment={environment}
                    options={options}
                />
            ))}
        </div>
    );
}
