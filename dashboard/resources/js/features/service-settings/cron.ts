/**
 * Cron expressions of scheduled cron services, in the grammar launchroomd
 * runs (daemon/internal/cronexpr, App\Support\Cron\CronGrammar): five fields
 * separated by single spaces, each a comma list of `*`, `n` or `a-b` with an
 * optional `/step`. Day of week 0-7 (0 and 7 are Sunday). When both day of
 * month and day of week are restricted, either matching is enough.
 */

type Field = { name: string; min: number; max: number };

const FIELDS: Field[] = [
    { name: 'minute', min: 0, max: 59 },
    { name: 'hour', min: 0, max: 23 },
    { name: 'day of month', min: 1, max: 31 },
    { name: 'month', min: 1, max: 12 },
    { name: 'day of week', min: 0, max: 7 },
];

export type ParsedCron = {
    minute: Set<number>;
    hour: Set<number>;
    dom: Set<number>;
    month: Set<number>;
    dow: Set<number>;
    domStar: boolean;
    dowStar: boolean;
    fields: string[];
};

const NUMBER = /^\d{1,2}$/;

function parseField(
    source: string,
    field: Field,
): { values: Set<number> } | { error: string } {
    if (source === '') {
        return { error: `The ${field.name} field is empty.` };
    }

    const values = new Set<number>();

    for (const item of source.split(',')) {
        let range = item;
        let step = 1;

        if (item.includes('/')) {
            const [head, stepSource] = [
                item.slice(0, item.indexOf('/')),
                item.slice(item.indexOf('/') + 1),
            ];

            if (
                !NUMBER.test(stepSource) ||
                Number(stepSource) < 1 ||
                Number(stepSource) > field.max
            ) {
                return {
                    error: `The ${field.name} step "${stepSource}" must be a number from 1 to ${field.max}.`,
                };
            }

            range = head;
            step = Number(stepSource);
        }

        let low = field.min;
        let high = field.max;

        if (range !== '*') {
            const bounds = range.split('-');

            if (bounds.length > 2) {
                return { error: `"${range}" isn't a ${field.name} range.` };
            }

            for (const bound of bounds) {
                if (!NUMBER.test(bound)) {
                    return {
                        error: `"${bound}" isn't a ${field.name}: use numbers (${field.min}-${field.max}), *, ranges, lists and steps.`,
                    };
                }

                const value = Number(bound);

                if (value < field.min || value > field.max) {
                    const name =
                        field.name.charAt(0).toUpperCase() +
                        field.name.slice(1);

                    return {
                        error: `${name} ${value} is outside ${field.min}-${field.max}.`,
                    };
                }
            }

            low = Number(bounds[0]);
            high =
                bounds.length === 2
                    ? Number(bounds[1])
                    : item.includes('/')
                      ? field.max
                      : low;

            if (low > high) {
                return {
                    error: `The ${field.name} range "${range}" runs backwards.`,
                };
            }
        }

        for (let value = low; value <= high; value += step) {
            values.add(value);
        }
    }

    return { values };
}

export function parseCron(
    expression: string,
): { cron: ParsedCron } | { error: string } {
    if (expression.length > 100) {
        return { error: 'Keep the cron expression under 100 characters.' };
    }

    const parts = expression.split(' ');

    if (parts.length !== 5) {
        return {
            error: 'Use five fields separated by single spaces: minute hour day month weekday, for example "0 3 * * *".',
        };
    }

    const sets: Set<number>[] = [];

    for (let index = 0; index < 5; index++) {
        const parsed = parseField(parts[index], FIELDS[index]);

        if ('error' in parsed) {
            return parsed;
        }

        sets.push(parsed.values);
    }

    const dow = sets[4];

    if (dow.has(7)) {
        dow.delete(7);
        dow.add(0);
    }

    return {
        cron: {
            minute: sets[0],
            hour: sets[1],
            dom: sets[2],
            month: sets[3],
            dow,
            domStar: parts[2].startsWith('*'),
            dowStar: parts[4].startsWith('*'),
            fields: parts,
        },
    };
}

/** "  0  3 * *  * " → "0 3 * * *": what people paste, in the stored shape. */
export function normalizeCron(expression: string): string {
    return expression.trim().split(/\s+/).join(' ');
}

// ---- Next runs in a time zone ------------------------------------------------

type Wall = {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
    weekday: number;
};

const WEEKDAY_INDEX: Record<string, number> = {
    Sun: 0,
    Mon: 1,
    Tue: 2,
    Wed: 3,
    Thu: 4,
    Fri: 5,
    Sat: 6,
};

const formatters = new Map<string, Intl.DateTimeFormat>();

function wallFormatter(timeZone: string): Intl.DateTimeFormat {
    let formatter = formatters.get(timeZone);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            hourCycle: 'h23',
            year: 'numeric',
            month: 'numeric',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            weekday: 'short',
        });
        formatters.set(timeZone, formatter);
    }

    return formatter;
}

function wallOf(instant: number, timeZone: string): Wall {
    const parts: Record<string, string> = {};

    for (const part of wallFormatter(timeZone).formatToParts(instant)) {
        parts[part.type] = part.value;
    }

    return {
        year: Number(parts.year),
        month: Number(parts.month),
        day: Number(parts.day),
        hour: Number(parts.hour) % 24,
        minute: Number(parts.minute),
        weekday: WEEKDAY_INDEX[parts.weekday] ?? 0,
    };
}

function wallKey(wall: Wall): number {
    return (
        ((wall.year * 100 + wall.month) * 100 + wall.day) * 10000 +
        wall.hour * 100 +
        wall.minute
    );
}

function dayMatches(cron: ParsedCron, wall: Wall): boolean {
    const dom = cron.dom.has(wall.day);
    const dow = cron.dow.has(wall.weekday);

    if (cron.domStar && cron.dowStar) {
        return true;
    }

    if (cron.domStar) {
        return dow;
    }

    if (cron.dowStar) {
        return dom;
    }

    return dom || dow;
}

export function isValidTimeZone(timeZone: string): boolean {
    try {
        wallFormatter(timeZone);

        return true;
    } catch {
        return false;
    }
}

const MINUTE = 60_000;

/**
 * The next `count` runs after `from`, as launchroomd starts them: every
 * matching wall clock minute in `timeZone`; minutes that don't exist when
 * clocks go forward are skipped, repeated ones run once. Looks five years
 * ahead at most.
 */
export function nextRuns(
    cron: ParsedCron,
    timeZone: string,
    count: number,
    from: number = Date.now(),
): Date[] {
    if (!isValidTimeZone(timeZone)) {
        return [];
    }

    const runs: Date[] = [];
    let last = wallKey(wallOf(from, timeZone));
    let instant = Math.floor(from / MINUTE) * MINUTE + MINUTE;
    const end = from + 5 * 366 * 24 * 60 * MINUTE;

    while (runs.length < count && instant < end) {
        const wall = wallOf(instant, timeZone);

        if (!cron.month.has(wall.month) || !dayMatches(cron, wall)) {
            const left = 24 * 60 - (wall.hour * 60 + wall.minute);

            // An hour short of midnight at most, so a DST shift never jumps past it.
            instant += (left > 60 ? left - 60 : left) * MINUTE;
            continue;
        }

        if (!cron.hour.has(wall.hour)) {
            instant += Math.max(1, 60 - wall.minute) * MINUTE;
            continue;
        }

        const key = wallKey(wall);

        if (cron.minute.has(wall.minute) && key > last) {
            runs.push(new Date(instant));
            last = key;
        }

        instant += MINUTE;
    }

    return runs;
}

// ---- In words -------------------------------------------------------------

const WEEKDAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const pad = (value: number) => String(value).padStart(2, '0');

function list(words: string[]): string {
    if (words.length <= 1) {
        return words.join('');
    }

    return `${words.slice(0, -1).join(', ')} and ${words[words.length - 1]}`;
}

function ordinal(value: number): string {
    const tens = value % 100;

    if (tens >= 11 && tens <= 13) {
        return `${value}th`;
    }

    return `${value}${['th', 'st', 'nd', 'rd'][value % 10] ?? 'th'}`;
}

const sorted = (values: Set<number>) => [...values].sort((a, b) => a - b);

/** `*` or `*` with a step: "every 5" etc. */
function stepOf(source: string): number | null {
    if (source === '*') {
        return 1;
    }

    const match = /^\*\/(\d{1,2})$/.exec(source);

    return match ? Number(match[1]) : null;
}

/** A contiguous run of values ("1-5"), else null. */
function span(values: number[]): [number, number] | null {
    if (values.length < 3) {
        return null;
    }

    return values.every(
        (value, index) => index === 0 || value === values[index - 1] + 1,
    )
        ? [values[0], values[values.length - 1]]
        : null;
}

function dayPhrase(cron: ParsedCron): string {
    const weekdays = sorted(cron.dow);
    const days = sorted(cron.dom);
    const months = sorted(cron.month);
    let phrase = '';

    const weekdayWords = () => {
        if (weekdays.join() === '1,2,3,4,5') {
            return 'every weekday';
        }

        if (weekdays.join() === '0,6') {
            return 'every Saturday and Sunday';
        }

        const run = span(weekdays);

        return run
            ? `${WEEKDAYS[run[0]]} to ${WEEKDAYS[run[1]]}`
            : `every ${list(weekdays.map((day) => WEEKDAYS[day]))}`;
    };

    const dayWords = () => {
        const run = span(days);

        return run
            ? `on days ${run[0]} to ${run[1]}`
            : `on the ${list(days.map(ordinal))}`;
    };

    if (cron.domStar && cron.dowStar) {
        phrase = months.length === 12 ? 'every day' : '';
    } else if (cron.domStar) {
        phrase = weekdayWords();
    } else if (cron.dowStar) {
        phrase = dayWords();
    } else {
        phrase = `${dayWords()} and ${weekdayWords()}`;
    }

    if (months.length < 12) {
        const monthWords = list(months.map((month) => MONTHS[month - 1]));

        phrase =
            cron.domStar && cron.dowStar
                ? `every day in ${monthWords}`
                : `${phrase} in ${monthWords}`;
    } else if (!cron.dowStar && cron.domStar) {
        // "every Monday" reads well alone.
    } else if (!cron.domStar && cron.dowStar) {
        phrase = `${phrase} of every month`;
    }

    return phrase;
}

/**
 * The schedule as one sentence: "Every 5 minutes", "Every day at 03:00",
 * "Every weekday at 09:30", "On the 1st of every month at 00:00".
 */
export function describeCron(cron: ParsedCron): string {
    const [minuteSource, hourSource] = cron.fields;
    const minutes = sorted(cron.minute);
    const hours = sorted(cron.hour);
    const everyDay = cron.domStar && cron.dowStar && cron.month.size === 12;
    const days = dayPhrase(cron);
    const minuteStep = stepOf(minuteSource);
    const hourStep = stepOf(hourSource);
    const capitalize = (text: string) =>
        text.charAt(0).toUpperCase() + text.slice(1);
    const onDays = everyDay ? '' : `, ${days}`;

    // Sub-hourly: every N minutes (in some hours).
    if (minuteStep !== null) {
        const every =
            minuteStep === 1 ? 'Every minute' : `Every ${minuteStep} minutes`;

        if (hourStep === 1) {
            return `${every}${onDays}`;
        }

        const run = span(hours);

        return run
            ? `${every} from ${pad(run[0])}:00 to ${pad(run[1])}:59${onDays}`
            : `${every} during hour${hours.length === 1 ? '' : 's'} ${list(hours.map((hour) => pad(hour)))}${onDays}`;
    }

    const minuteWords =
        minutes.length === 1
            ? minutes[0] === 0
                ? 'on the hour'
                : `at :${pad(minutes[0])}`
            : `at ${list(minutes.map((minute) => `:${pad(minute)}`))}`;

    if (hourStep !== null) {
        const every = hourStep === 1 ? 'Every hour' : `Every ${hourStep} hours`;

        return `${every} ${minuteWords}${onDays}`;
    }

    if (hours.length * minutes.length <= 6) {
        const times = hours.flatMap((hour) =>
            minutes.map((minute) => `${pad(hour)}:${pad(minute)}`),
        );

        return `${capitalize(days)} at ${list(times)}`;
    }

    const run = span(hours);
    const hourWords = run
        ? `from ${pad(run[0])}:00 to ${pad(run[1])}:59`
        : `in hours ${list(hours.map((hour) => pad(hour)))}`;

    return `${capitalize(days)}, ${hourWords}, ${minuteWords}`;
}

export type CronPreset = {
    label: string;
    cron: string;
};

export const CRON_PRESETS: CronPreset[] = [
    { label: 'Every 5 minutes', cron: '*/5 * * * *' },
    { label: 'Every 15 minutes', cron: '*/15 * * * *' },
    { label: 'Hourly', cron: '0 * * * *' },
    { label: 'Every 6 hours', cron: '0 */6 * * *' },
    { label: 'Daily at 03:00', cron: '0 3 * * *' },
    { label: 'Weekdays at 09:00', cron: '0 9 * * 1-5' },
    { label: 'Weekly on Monday', cron: '0 9 * * 1' },
    { label: 'Monthly on the 1st', cron: '0 0 1 * *' },
];
