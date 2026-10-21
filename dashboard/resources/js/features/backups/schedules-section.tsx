import { useForm } from '@inertiajs/react';
import { Database, HardDrive, Shield } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { Stepper } from '@/components/patterns/stepper';
import { RunStrip } from '@/components/steps/run-strip';
import { relativeTime } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { BackupHealthStatus } from '@/features/backups/backup-health';
import { formatBytes } from '@/features/backups/format';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { update } from '@/routes/backups/schedules';

type Schedule = App.Data.Backups.BackupScheduleData;
type Protection = App.Data.Backups.BackupProtectionData;

type Frequency = 'hourly' | 'every' | 'daily' | 'weekly' | 'custom';

type Picked = {
    frequency: Frequency;
    /** "HH:MM" (UTC) for daily and weekly. */
    time: string;
    minute: number;
    every: number;
    weekday: number;
};

const weekdays = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

const pad = (value: number) => String(value).padStart(2, '0');

/** The shapes the picker writes; anything else stays "Custom" (raw cron). */
export function parseCron(cron: string): Picked {
    const base: Picked = {
        frequency: 'custom',
        time: '03:00',
        minute: 0,
        every: 6,
        weekday: 1,
    };
    const fields = cron.trim().split(/\s+/);

    if (fields.length !== 5 || !/^\d{1,2}$/.test(fields[0])) {
        return base;
    }

    const [minute, hour, day, month, weekday] = fields;
    const m = Number(minute);

    if (day !== '*' || month !== '*') {
        return base;
    }

    if (hour === '*' && weekday === '*') {
        return { ...base, frequency: 'hourly', minute: m };
    }

    const every = /^\*\/(\d{1,2})$/.exec(hour);

    if (every && weekday === '*') {
        return {
            ...base,
            frequency: 'every',
            minute: m,
            every: Number(every[1]),
        };
    }

    if (!/^\d{1,2}$/.test(hour)) {
        return base;
    }

    const time = `${pad(Number(hour))}:${pad(m)}`;

    if (weekday === '*') {
        return { ...base, frequency: 'daily', time, minute: m };
    }

    if (/^[0-7]$/.test(weekday)) {
        return {
            ...base,
            frequency: 'weekly',
            time,
            minute: m,
            weekday: Number(weekday) % 7,
        };
    }

    return base;
}

export function toCron(picked: Picked, custom: string): string {
    const [hour, minute] = picked.time.split(':').map(Number);

    switch (picked.frequency) {
        case 'hourly':
            return `${picked.minute} * * * *`;
        case 'every':
            return `${picked.minute} */${picked.every} * * *`;
        case 'daily':
            return `${minute} ${hour} * * *`;
        case 'weekly':
            return `${minute} ${hour} * * ${picked.weekday}`;
        case 'custom':
            return custom;
    }
}

const retentionFields: [keyof App.Data.Backups.RetentionData, string][] = [
    ['keepDaily', 'Daily'],
    ['keepWeekly', 'Weekly'],
    ['keepMonthly', 'Monthly'],
    ['keepHourly', 'Hourly'],
];

/**
 * Edit a schedule as a picker (docs/design/flows.md §14): how often, at
 * what time (UTC), how many snapshots to keep; raw cron only under Custom.
 */
export function ScheduleSheet({
    schedule,
    open,
    onOpenChange,
    children,
}: {
    schedule: Schedule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Extra sections (recent runs of launchroom itself). */
    children?: ReactNode;
}) {
    const initial = parseCron(schedule.cron);
    const [picked, setPicked] = useState<Picked>(initial);
    const form = useForm({
        cron: schedule.cron,
        retention: { ...schedule.retention },
        enabled: schedule.enabled,
    });
    const set = (next: Partial<Picked>) => setPicked({ ...picked, ...next });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            cron: toCron(picked, data.cron),
        }));
        form.put(update(schedule.id).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const id = schedule.id;

    return (
        <SidePanel
            open={open}
            onOpenChange={onOpenChange}
            size="sm"
            title={schedule.label}
            description={`Stored in ${schedule.destinationName}.`}
            footer={
                schedule.canManage && (
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            form={`schedule-${id}`}
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            Save schedule
                        </Button>
                    </div>
                )
            }
        >
            <form id={`schedule-${id}`} onSubmit={submit} className="contents">
                <SheetSection
                    title="Back up automatically"
                    description={
                        form.data.enabled
                            ? 'On a schedule, plus before deploys and deletes.'
                            : 'Paused: only manual and safety backups run.'
                    }
                    control={
                        <Switch
                            checked={form.data.enabled}
                            onCheckedChange={(checked) =>
                                form.setData('enabled', checked)
                            }
                            disabled={!schedule.canManage}
                            aria-label="Back up automatically"
                        />
                    }
                />
                <SheetSection title="When">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor={`${id}-frequency`}>How often</Label>
                            <Select
                                value={picked.frequency}
                                onValueChange={(value) =>
                                    set({ frequency: value as Frequency })
                                }
                                disabled={!schedule.canManage}
                            >
                                <SelectTrigger
                                    id={`${id}-frequency`}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="hourly">
                                        Every hour
                                    </SelectItem>
                                    <SelectItem value="every">
                                        Every few hours
                                    </SelectItem>
                                    <SelectItem value="daily">Daily</SelectItem>
                                    <SelectItem value="weekly">
                                        Weekly
                                    </SelectItem>
                                    <SelectItem value="custom">
                                        Custom (cron)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {picked.frequency === 'every' && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${id}-every`}>Every</Label>
                                <Select
                                    value={String(picked.every)}
                                    onValueChange={(value) =>
                                        set({ every: Number(value) })
                                    }
                                >
                                    <SelectTrigger
                                        id={`${id}-every`}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[2, 3, 4, 6, 8, 12].map((hours) => (
                                            <SelectItem
                                                key={hours}
                                                value={String(hours)}
                                            >
                                                {hours} hours
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        {picked.frequency === 'weekly' && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${id}-weekday`}>On</Label>
                                <Select
                                    value={String(picked.weekday)}
                                    onValueChange={(value) =>
                                        set({ weekday: Number(value) })
                                    }
                                >
                                    <SelectTrigger
                                        id={`${id}-weekday`}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {weekdays.map((day, index) => (
                                            <SelectItem
                                                key={day}
                                                value={String(index)}
                                            >
                                                {day}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        {(picked.frequency === 'daily' ||
                            picked.frequency === 'weekly') && (
                            <div className="grid content-start gap-2">
                                <Label htmlFor={`${id}-time`}>At (UTC)</Label>
                                <Input
                                    id={`${id}-time`}
                                    type="time"
                                    value={picked.time}
                                    onChange={(event) =>
                                        set({ time: event.target.value })
                                    }
                                    required
                                    className="tabular-nums"
                                />
                            </div>
                        )}

                        {picked.frequency === 'custom' && (
                            <div className="grid content-start gap-2 sm:col-span-2">
                                <Label htmlFor={`${id}-cron`}>
                                    Cron expression (UTC)
                                </Label>
                                <Input
                                    id={`${id}-cron`}
                                    value={form.data.cron}
                                    onChange={(event) =>
                                        form.setData('cron', event.target.value)
                                    }
                                    required
                                    className="font-mono"
                                />
                                <p className="type-small text-muted-foreground">
                                    minute hour day month weekday, e.g.{' '}
                                    <code className="type-mono-small">
                                        15 3 * * *
                                    </code>
                                </p>
                            </div>
                        )}
                    </div>
                    <InputError message={form.errors.cron} />
                </SheetSection>
                <SheetSection
                    title="Keep"
                    description="Older snapshots are removed once a day."
                >
                    <div className="grid grid-cols-2 gap-x-4 gap-y-5">
                        {retentionFields.map(([field, label]) => (
                            <Stepper
                                key={field}
                                label={label}
                                value={form.data.retention[field]}
                                min={0}
                                max={1000}
                                disabled={!schedule.canManage}
                                onChange={(value) =>
                                    form.setData('retention', {
                                        ...form.data.retention,
                                        [field]: value,
                                    })
                                }
                            />
                        ))}
                    </div>
                    <InputError message={form.errors.retention} />
                </SheetSection>
            </form>
            {children}
        </SidePanel>
    );
}

function dayLabel(
    date: string,
    status: App.Data.Backups.BackupDayData['status'],
) {
    const day = new Date(`${date}T00:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
    const word =
        status === 'ok'
            ? 'backed up'
            : status === 'failed'
              ? 'failed'
              : 'no backup';

    return `${day} · ${word}`;
}

const icons = {
    database: Database,
    volume: HardDrive,
    control_plane: Shield,
    base_backup: Database,
} as const;

/**
 * One protected thing (docs/design/flows.md §14): what it is, its schedule
 * as a sentence, the last 30 days as a run strip, and when it last
 * succeeded. Clicking Edit opens the schedule sheet.
 */
export function ScheduleRow({
    schedule,
    protection,
    label,
    description,
    actions,
    sheetExtra,
}: {
    schedule: Schedule;
    protection?: Protection;
    label?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    sheetExtra?: ReactNode;
}) {
    const [editing, setEditing] = useState(false);
    const Icon = icons[schedule.subject];
    const when = schedule.cronDescription ?? 'Custom schedule';
    const last = protection?.lastSucceededAt ?? null;
    const healthy =
        schedule.health.status === 'ok' || schedule.health.status === 'running';

    return (
        <SettingsRow
            media={
                <span className="flex size-9 items-center justify-center rounded-md border bg-card text-muted-foreground">
                    <Icon aria-hidden="true" className="size-4" />
                </span>
            }
            label={
                label ?? (
                    <span>
                        {schedule.label}
                        {schedule.projectName !== null && (
                            <span className="font-normal text-muted-foreground">
                                {' '}
                                · {schedule.projectName} ·{' '}
                                {schedule.environmentName}
                            </span>
                        )}
                    </span>
                )
            }
            description={
                description ??
                (schedule.enabled
                    ? `${when} · keeps ${schedule.retentionSummary}`
                    : 'Paused')
            }
        >
            {protection &&
                protection.days.some((day) => day.status !== 'none') && (
                    <RunStrip
                        runs={protection.days.map((day) => ({
                            key: day.date,
                            status: day.status as 'ok' | 'failed' | 'none',
                            label: dayLabel(day.date, day.status),
                        }))}
                        label={`${schedule.label}, last 30 days`}
                        height={18}
                        className="hidden w-36 md:block"
                    />
                )}
            <span className="type-small text-muted-foreground tabular-nums sm:w-32 sm:text-right">
                {healthy && last !== null ? (
                    <>
                        {relativeTime(last)}
                        {protection?.lastSizeBytes != null &&
                            ` · ${formatBytes(protection.lastSizeBytes)}`}
                    </>
                ) : (
                    <BackupHealthStatus health={schedule.health} />
                )}
            </span>
            {actions}
            <Button variant="ghost" size="sm" onClick={() => setEditing(true)}>
                {schedule.canManage ? 'Edit' : 'View'}
            </Button>
            <ScheduleSheet
                schedule={schedule}
                open={editing}
                onOpenChange={setEditing}
            >
                {sheetExtra}
            </ScheduleSheet>
        </SettingsRow>
    );
}

/**
 * What's protected (Settings → Backups): one row per backed-up thing.
 */
export function SchedulesSection({
    schedules,
    protection,
    leading,
}: {
    schedules: Schedule[];
    protection: Protection[];
    /** launchroom itself, first (instance owners). */
    leading?: ReactNode;
}) {
    if (schedules.length === 0 && !leading) {
        return null;
    }

    return (
        <SettingsCard
            title="What’s protected"
            description="Last 30 days"
            footer={
                schedules.length === 0
                    ? 'Databases in production and persistent environments show up here once a destination exists.'
                    : 'Production and persistent databases are backed up daily. Previews aren’t backed up.'
            }
        >
            {leading}
            {schedules.map((schedule) => (
                <ScheduleRow
                    key={schedule.id}
                    schedule={schedule}
                    protection={protection.find(
                        (item) => item.scheduleId === schedule.id,
                    )}
                />
            ))}
        </SettingsCard>
    );
}
