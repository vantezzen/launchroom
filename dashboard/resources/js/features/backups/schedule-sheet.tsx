import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { Stepper } from '@/components/patterns/stepper';
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

/** What the sheet edits; `updateUrl` null = read only. */
export type EditableSchedule = {
    id: string;
    label: string;
    destinationName: string;
    cron: string;
    retention: App.Data.Backups.RetentionData;
    retentionLimits: App.Data.Backups.RetentionLimitsData;
    enabled: boolean;
    updateUrl: string | null;
};

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

function toCron(picked: Picked, custom: string): string {
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

const retentionFields: [keyof App.Data.Backups.RetentionLimitsData, string][] =
    [
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
    schedule: EditableSchedule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Extra sections (what else shares the schedule). */
    children?: ReactNode;
}) {
    const canManage = schedule.updateUrl !== null;
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
        form.put(schedule.updateUrl ?? '', {
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
                canManage && (
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
                            ? 'Backups run on this schedule, and before restores, updates and deletes.'
                            : 'Paused. Only manual and safety backups run.'
                    }
                    control={
                        <Switch
                            checked={form.data.enabled}
                            onCheckedChange={(checked) =>
                                form.setData('enabled', checked)
                            }
                            disabled={!canManage}
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
                                disabled={!canManage}
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
                                min={schedule.retentionLimits[field].min}
                                max={schedule.retentionLimits[field].max}
                                disabled={!canManage}
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
