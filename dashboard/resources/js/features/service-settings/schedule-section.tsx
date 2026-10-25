import { useForm } from '@inertiajs/react';
import { CalendarClock, Infinity as Continuous } from 'lucide-react';
import { useMemo } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { MetaLine } from '@/components/patterns/meta-line';
import { TimeRangeSelect } from '@/components/charts/time-range-select';
import { relativeTime } from '@/components/time-ago';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    SettingsBody,
    SettingsCard,
    SettingsRow,
} from '@/features/settings/settings-card';
import { cn } from '@/lib/utils';
import services from '@/routes/projects/services';
import {
    CRON_PRESETS,
    describeCron,
    isValidTimeZone,
    nextRuns,
    normalizeCron,
    parseCron,
} from './cron';
import { IconTile, SaveRow, SettingsPage } from './section';
import type { SettingsContext } from './settings-context';

type Mode = 'schedule' | 'continuous';

type ScheduleForm = {
    mode: Mode;
    cron: string;
    timeZone: string;
};

const FIELD_NAMES = ['minute', 'hour', 'day', 'month', 'weekday'];

/** The viewer's zone, as the default for a new schedule. */
function browserZone(): string {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return zone && isValidTimeZone(zone) ? zone : 'UTC';
    } catch {
        return 'UTC';
    }
}

function zones(current: string): string[] {
    let all: string[] = [];

    try {
        all = (
            Intl as unknown as { supportedValuesOf(key: string): string[] }
        ).supportedValuesOf('timeZone');
    } catch {
        all = [];
    }

    return Array.from(new Set(['UTC', current, ...all]));
}

const zoneLabel = (zone: string) => zone.replace(/_/g, ' ');

function formatRun(date: Date, timeZone: string) {
    return {
        day: new Intl.DateTimeFormat(undefined, {
            timeZone,
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        }).format(date),
        time: new Intl.DateTimeFormat(undefined, {
            timeZone,
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(date),
    };
}

/**
 * Schedule (cron services): when launchroomd starts the job's command. A
 * cron expression with presets, the schedule in words and its next five
 * runs in the chosen time zone. launchroomd keeps the schedule while the
 * dashboard is unreachable; a run still going when the next one is due
 * makes that one skip (docs/04 §1).
 */
export function ScheduleSection({ context }: { context: SettingsContext }) {
    const { settings, canUpdate } = context;
    const stored = settings.deploy.schedule;
    const form = useForm<ScheduleForm>({
        mode: stored ? 'schedule' : 'continuous',
        cron: stored?.cron ?? '0 3 * * *',
        timeZone: stored?.timeZone ?? browserZone(),
    });
    const data = form.data;
    const error = (key: string) =>
        (form.errors as Record<string, string | undefined>)[
            `deploy.schedule.${key}`
        ] ??
        (form.errors as Record<string, string | undefined>)['deploy.schedule'];
    const cron = normalizeCron(data.cron);
    const parsed = parseCron(cron);
    const viewerZone = browserZone();
    const runs = useMemo(() => {
        const current = parseCron(cron);

        return 'cron' in current
            ? nextRuns(current.cron, data.timeZone, 5)
            : [];
    }, [cron, data.timeZone]);
    const sentence = 'cron' in parsed ? describeCron(parsed.cron) : null;
    const zoneOptions = useMemo(() => zones(data.timeZone), [data.timeZone]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (data.mode === 'schedule' && 'error' in parsed) {
            form.setError('cron', parsed.error);

            return;
        }

        form.transform((values) => ({
            deploy: {
                ...settings.deploy,
                schedule:
                    values.mode === 'schedule'
                        ? { cron, timeZone: values.timeZone }
                        : null,
            },
        }));
        form.patch(services.settings(context.route).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => form.setDefaults(),
        });
    };

    return (
        <SettingsPage
            title="Schedule"
            description="When this job runs its command. Servers keep the schedule even while the dashboard is unreachable."
        >
            <form onSubmit={submit} className="flex flex-col gap-8">
                <SettingsCard>
                    <SettingsRow
                        setting="schedule-mode"
                        label="Runs"
                        description={
                            data.mode === 'schedule'
                                ? 'Starts the command at every matching minute. If a run is still going, the next one is skipped.'
                                : 'Runs the command continuously and restarts it when it exits, like a worker.'
                        }
                    >
                        <TimeRangeSelect<Mode>
                            label="When it runs"
                            value={data.mode}
                            onChange={(mode) =>
                                canUpdate && form.setData('mode', mode)
                            }
                            options={[
                                { value: 'schedule', label: 'On a schedule' },
                                { value: 'continuous', label: 'Continuously' },
                            ]}
                        />
                    </SettingsRow>
                </SettingsCard>

                {data.mode === 'continuous' ? (
                    <SettingsCard>
                        <SettingsRow
                            media={
                                <IconTile>
                                    <Continuous />
                                </IconTile>
                            }
                            label="No schedule"
                            description="Choose “On a schedule” to run it every few minutes, nightly or weekly."
                        />
                    </SettingsCard>
                ) : (
                    <>
                        <SettingsCard setting="schedule" title="Schedule">
                            <SettingsBody className="flex flex-col gap-5">
                                <div
                                    className="flex items-start gap-3"
                                    aria-live="polite"
                                >
                                    <IconTile>
                                        <CalendarClock />
                                    </IconTile>
                                    <div className="flex min-w-0 flex-col gap-0.5">
                                        <p className="text-[17px] leading-6 font-semibold tracking-[-0.01em]">
                                            {sentence ?? 'Not a valid schedule'}
                                        </p>
                                        <p className="type-small text-muted-foreground">
                                            {sentence ? (
                                                runs.length > 0 ? (
                                                    <MetaLine>
                                                        {zoneLabel(
                                                            data.timeZone,
                                                        )}
                                                        {`Next run ${relativeTime(runs[0].toISOString())}`}
                                                    </MetaLine>
                                                ) : (
                                                    <MetaLine>
                                                        {zoneLabel(
                                                            data.timeZone,
                                                        )}
                                                        Never matches a date
                                                    </MetaLine>
                                                )
                                            ) : (
                                                'Fix the expression below.'
                                            )}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    role="group"
                                    aria-label="Presets"
                                    className="flex flex-wrap gap-2"
                                >
                                    {CRON_PRESETS.map((preset) => {
                                        const selected = preset.cron === cron;

                                        return (
                                            <button
                                                key={preset.cron}
                                                type="button"
                                                aria-pressed={selected}
                                                disabled={!canUpdate}
                                                onClick={() => {
                                                    form.setData(
                                                        'cron',
                                                        preset.cron,
                                                    );
                                                    form.clearErrors('cron');
                                                }}
                                                className={cn(
                                                    'h-8 rounded-full border px-3 type-label transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60',
                                                    selected
                                                        ? 'border-foreground bg-foreground text-background'
                                                        : 'bg-card text-muted-foreground hover:border-border-strong hover:text-foreground',
                                                )}
                                            >
                                                {preset.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </SettingsBody>

                            <SettingsRow
                                setting="schedule-cron"
                                label="Cron expression"
                                description="Minute, hour, day, month and weekday. Numbers, *, ranges (1-5), lists (1,15) and steps (*/10)."
                                htmlFor="schedule-cron"
                                align="start"
                            >
                                <div className="flex w-full flex-col gap-1.5 sm:w-64">
                                    <Input
                                        id="schedule-cron"
                                        value={data.cron}
                                        disabled={!canUpdate}
                                        spellCheck={false}
                                        autoComplete="off"
                                        aria-invalid={'error' in parsed}
                                        className="font-mono text-[14px] tracking-[0.08em]"
                                        onChange={(event) => {
                                            form.setData(
                                                'cron',
                                                event.target.value,
                                            );
                                            form.clearErrors('cron');
                                        }}
                                    />
                                    <div
                                        aria-hidden="true"
                                        className="grid grid-cols-5 gap-1 px-1 text-[11px] leading-4 text-muted-foreground"
                                    >
                                        {FIELD_NAMES.map((name, index) => (
                                            <span
                                                key={name}
                                                className="flex flex-col"
                                            >
                                                <span className="truncate font-mono text-foreground/80">
                                                    {cron.split(' ')[index] ??
                                                        '–'}
                                                </span>
                                                {name}
                                            </span>
                                        ))}
                                    </div>
                                    <InputError
                                        message={
                                            ('error' in parsed
                                                ? parsed.error
                                                : undefined) ??
                                            form.errors.cron ??
                                            error('cron')
                                        }
                                    />
                                </div>
                            </SettingsRow>

                            <SettingsRow
                                setting="schedule-time-zone"
                                label="Time zone"
                                description="The clock the schedule follows, including daylight saving time."
                            >
                                <div className="flex flex-col gap-1">
                                    <Select
                                        value={data.timeZone}
                                        disabled={!canUpdate}
                                        onValueChange={(zone) =>
                                            form.setData('timeZone', zone)
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-64"
                                            aria-label="Time zone"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent className="max-h-72">
                                            {zoneOptions.map((zone) => (
                                                <SelectItem
                                                    key={zone}
                                                    value={zone}
                                                >
                                                    {zoneLabel(zone)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={error('timeZone')} />
                                </div>
                            </SettingsRow>
                        </SettingsCard>

                        <SettingsCard
                            setting="schedule-next-runs"
                            title="Next runs"
                            description={
                                data.timeZone === viewerZone
                                    ? `In ${zoneLabel(data.timeZone)}.`
                                    : `In ${zoneLabel(data.timeZone)}, with your time beside it.`
                            }
                        >
                            {runs.length === 0 ? (
                                <SettingsRow
                                    label={
                                        sentence
                                            ? 'No run in the next five years'
                                            : 'No runs'
                                    }
                                    description={
                                        sentence
                                            ? 'The day and month never meet, such as 30 February.'
                                            : 'Fix the expression to see when it runs.'
                                    }
                                />
                            ) : (
                                <ol className="divide-y">
                                    {runs.map((run, index) => {
                                        const at = formatRun(
                                            run,
                                            data.timeZone,
                                        );
                                        const local = formatRun(
                                            run,
                                            viewerZone,
                                        );

                                        return (
                                            <li
                                                key={run.toISOString()}
                                                className="flex items-center gap-4 px-5 py-3"
                                            >
                                                <span
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-2 shrink-0 rounded-full',
                                                        index === 0
                                                            ? 'bg-foreground'
                                                            : 'border border-border-strong',
                                                    )}
                                                />
                                                <span className="w-28 type-body">
                                                    {at.day}
                                                </span>
                                                <span className="type-mono-small tabular-nums">
                                                    {at.time}
                                                </span>
                                                {data.timeZone !==
                                                    viewerZone && (
                                                    <span className="type-small text-muted-foreground tabular-nums">
                                                        {local.day === at.day
                                                            ? local.time
                                                            : `${local.day} ${local.time}`}{' '}
                                                        your time
                                                    </span>
                                                )}
                                                <span className="ml-auto type-small text-muted-foreground">
                                                    {relativeTime(
                                                        run.toISOString(),
                                                    )}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ol>
                            )}
                        </SettingsCard>
                    </>
                )}

                {canUpdate && (
                    <SaveRow
                        dirty={form.isDirty}
                        processing={form.processing}
                        onReset={() => {
                            form.reset();
                            form.clearErrors();
                        }}
                        label="Save for next deploy"
                    />
                )}
            </form>
        </SettingsPage>
    );
}
