import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { CronBuilder } from './cron';

type Every = CronBuilder['every'];

const EVERY: { value: Every; label: string }[] = [
    { value: 'minutes', label: 'Every few minutes' },
    { value: 'hours', label: 'Every few hours' },
    { value: 'days', label: 'Every day' },
    { value: 'month', label: 'Every month' },
];

/** Monday first, as calendars in most places show it; cron counts Sunday as 0. */
const WEEKDAYS = [
    { day: 1, short: 'M', name: 'Monday' },
    { day: 2, short: 'T', name: 'Tuesday' },
    { day: 3, short: 'W', name: 'Wednesday' },
    { day: 4, short: 'T', name: 'Thursday' },
    { day: 5, short: 'F', name: 'Friday' },
    { day: 6, short: 'S', name: 'Saturday' },
    { day: 0, short: 'S', name: 'Sunday' },
];

const pad = (value: number) => String(value).padStart(2, '0');

/** Switching how often keeps the time of day where both have one. */
function switchTo(every: Every, from: CronBuilder): CronBuilder {
    const minute = 'minute' in from ? from.minute : 0;
    const hour = 'hour' in from ? from.hour : 3;

    switch (every) {
        case 'minutes':
            return { every, step: 15 };
        case 'hours':
            return { every, step: 1, minute };
        case 'days':
            return { every, hour, minute, weekdays: [] };
        case 'month':
            return { every, day: 1, hour, minute };
    }
}

function clamp(value: string, min: number, max: number, fallback: number) {
    const number = Number.parseInt(value, 10);

    return Number.isNaN(number)
        ? fallback
        : Math.min(max, Math.max(min, number));
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="flex items-center gap-2 type-body">
            <span className="text-muted-foreground">{label}</span>
            {children}
        </label>
    );
}

function NumberInput({
    value,
    min,
    max,
    label,
    disabled,
    onChange,
}: {
    value: number;
    min: number;
    max: number;
    label: string;
    disabled: boolean;
    onChange: (value: number) => void;
}) {
    return (
        <Input
            type="number"
            inputMode="numeric"
            min={min}
            max={max}
            value={value}
            aria-label={label}
            disabled={disabled}
            className="h-9 w-20 tabular-nums"
            onChange={(event) =>
                onChange(clamp(event.target.value, min, max, value))
            }
        />
    );
}

function TimeInput({
    hour,
    minute,
    disabled,
    onChange,
}: {
    hour: number;
    minute: number;
    disabled: boolean;
    onChange: (hour: number, minute: number) => void;
}) {
    return (
        <Input
            type="time"
            value={`${pad(hour)}:${pad(minute)}`}
            aria-label="Time"
            disabled={disabled}
            className="h-9 w-32 tabular-nums"
            onChange={(event) => {
                const [h, m] = event.target.value.split(':');

                if (h !== undefined && m !== undefined) {
                    onChange(clamp(h, 0, 23, hour), clamp(m, 0, 59, minute));
                }
            }}
        />
    );
}

/**
 * The schedule as fields (Retool, AirOps): how often, then the minute,
 * time, weekdays or day of the month that frequency needs. Writes a cron
 * expression through `fromBuilder`; the page shows it below as a preview.
 */
export function ScheduleBuilder({
    value,
    onChange,
    disabled,
}: {
    value: CronBuilder;
    onChange: (value: CronBuilder) => void;
    disabled: boolean;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
                <Select
                    value={value.every}
                    disabled={disabled}
                    onValueChange={(every) =>
                        onChange(switchTo(every as Every, value))
                    }
                >
                    <SelectTrigger className="h-9 w-48" aria-label="How often">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {EVERY.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {value.every === 'minutes' && (
                    <Field label="Every">
                        <NumberInput
                            value={value.step}
                            min={1}
                            max={59}
                            label="Minutes between runs"
                            disabled={disabled}
                            onChange={(step) => onChange({ ...value, step })}
                        />
                        <span className="text-muted-foreground">minutes</span>
                    </Field>
                )}

                {value.every === 'hours' && (
                    <>
                        <Field label="Every">
                            <NumberInput
                                value={value.step}
                                min={1}
                                max={23}
                                label="Hours between runs"
                                disabled={disabled}
                                onChange={(step) =>
                                    onChange({ ...value, step })
                                }
                            />
                            <span className="text-muted-foreground">
                                {value.step === 1 ? 'hour' : 'hours'}
                            </span>
                        </Field>
                        <Field label="At minute">
                            <NumberInput
                                value={value.minute}
                                min={0}
                                max={59}
                                label="Minute of the hour"
                                disabled={disabled}
                                onChange={(minute) =>
                                    onChange({ ...value, minute })
                                }
                            />
                        </Field>
                    </>
                )}

                {value.every === 'month' && (
                    <Field label="On day">
                        <NumberInput
                            value={value.day}
                            min={1}
                            max={31}
                            label="Day of the month"
                            disabled={disabled}
                            onChange={(day) => onChange({ ...value, day })}
                        />
                    </Field>
                )}

                {(value.every === 'days' || value.every === 'month') && (
                    <Field label="At">
                        <TimeInput
                            hour={value.hour}
                            minute={value.minute}
                            disabled={disabled}
                            onChange={(hour, minute) =>
                                onChange({ ...value, hour, minute })
                            }
                        />
                    </Field>
                )}
            </div>

            {value.every === 'days' && (
                <div
                    role="group"
                    aria-label="Weekdays"
                    className="flex flex-wrap items-center gap-1.5"
                >
                    {WEEKDAYS.map(({ day, short, name }) => {
                        const all = value.weekdays.length === 0;
                        const on = all || value.weekdays.includes(day);

                        return (
                            <button
                                key={day}
                                type="button"
                                aria-pressed={on}
                                aria-label={name}
                                title={name}
                                disabled={disabled}
                                onClick={() => {
                                    const current = all
                                        ? WEEKDAYS.map((item) => item.day)
                                        : value.weekdays;
                                    const next = on
                                        ? current.filter((item) => item !== day)
                                        : [...current, day];

                                    // No day left means every day.
                                    onChange({ ...value, weekdays: next });
                                }}
                                className={cn(
                                    'size-8 rounded-full border type-label transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60',
                                    on
                                        ? 'border-foreground bg-foreground text-background'
                                        : 'bg-card text-muted-foreground hover:border-border-strong hover:text-foreground',
                                )}
                            >
                                {short}
                            </button>
                        );
                    })}
                    <span className="ml-1 type-small text-muted-foreground">
                        {value.weekdays.length === 0 ||
                        value.weekdays.length === 7
                            ? 'Every day'
                            : 'Only on the marked days'}
                    </span>
                </div>
            )}
        </div>
    );
}
