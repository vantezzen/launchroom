import { useState } from 'react';
import type { StatusState } from '@/components/status';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';

type PointInTime = App.Data.Backups.PointInTimeData;

const keepOptions = [1, 3, 7, 14, 30];

/** "Oct 12, 03:00" in the viewer's zone. */
export function pointLabel(iso: string): string {
    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function shipping(pitr: PointInTime): { state: StatusState; label: string } {
    if (pitr.state === 'failing') {
        return { state: 'failed', label: 'WAL isn’t reaching the destination' };
    }

    if (pitr.windowStart === null) {
        return { state: 'progress', label: 'Taking the first base backup' };
    }

    if (pitr.state === 'waiting') {
        return { state: 'warning', label: 'Waiting for Postgres to run' };
    }

    return { state: 'active', label: 'Continuous' };
}

/**
 * Point-in-time recovery for Postgres (docs/14 §10; design-system §13.9
 * "Backups tab"): one calm card under the protection card. Off: what it
 * gives and the switch. On: whether WAL keeps flowing, the window a time
 * can be picked from, how far back it reaches.
 */
export function PointInTimeCard({
    pitr,
    busy,
    onChange,
}: {
    pitr: PointInTime;
    busy: boolean;
    onChange: (enabled: boolean, keepDays: number) => void;
}) {
    const [keepDays, setKeepDays] = useState(pitr.keepDays);

    if (!pitr.supported) {
        return null;
    }

    const status = shipping(pitr);

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex items-start justify-between gap-4 p-5">
                <div className="flex max-w-[60ch] min-w-0 flex-1 flex-col gap-1">
                    <span className="type-heading">Point-in-time recovery</span>
                    <p className="type-small text-muted-foreground">
                        {pitr.enabled
                            ? 'Every change is kept, so you can restore to any second inside the window.'
                            : 'Restore to any second instead of only the last snapshot. Postgres restarts once to turn it on.'}
                    </p>
                </div>
                {pitr.canConfigure && (
                    <div className="flex items-center gap-2">
                        {busy && <Spinner />}
                        <Switch
                            checked={pitr.enabled}
                            disabled={busy || !pitr.serverReady}
                            aria-label="Point-in-time recovery"
                            onCheckedChange={(checked) =>
                                onChange(checked, keepDays)
                            }
                        />
                    </div>
                )}
            </div>

            {!pitr.serverReady && (
                <p className="border-t px-5 py-3 type-small text-muted-foreground">
                    Update the server running this database to turn this on.
                </p>
            )}

            {pitr.enabled && (
                <>
                    {pitr.message !== null && (
                        <p className="border-t px-5 py-3 type-mono-small break-words text-status-error">
                            {pitr.message}
                        </p>
                    )}
                    <dl className="grid grid-cols-1 gap-3 border-t bg-recessed px-5 py-3 sm:grid-cols-3">
                        <div className="flex min-w-0 flex-col">
                            <dt className="type-small text-muted-foreground">
                                Status
                            </dt>
                            <dd className="truncate type-body">
                                <Status
                                    state={status.state}
                                    label={status.label}
                                />
                            </dd>
                        </div>
                        <div className="flex min-w-0 flex-col">
                            <dt className="type-small text-muted-foreground">
                                Restorable
                            </dt>
                            <dd className="truncate type-body">
                                {pitr.windowStart !== null ? (
                                    <span
                                        title={
                                            pitr.lastArchivedAt !== null
                                                ? `Last change stored ${new Date(pitr.lastArchivedAt).toLocaleString()}`
                                                : undefined
                                        }
                                    >
                                        Since {pointLabel(pitr.windowStart)}
                                    </span>
                                ) : (
                                    'After the first base backup'
                                )}
                            </dd>
                        </div>
                        <div className="flex min-w-0 flex-col">
                            <dt className="type-small text-muted-foreground">
                                Reaches back
                            </dt>
                            <dd className="type-body">
                                {pitr.canConfigure ? (
                                    <Select
                                        value={String(keepDays)}
                                        disabled={busy}
                                        onValueChange={(value) => {
                                            setKeepDays(Number(value));
                                            onChange(true, Number(value));
                                        }}
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="w-32"
                                            aria-label="How far back"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {keepOptions.map((days) => (
                                                <SelectItem
                                                    key={days}
                                                    value={String(days)}
                                                >
                                                    {days === 1
                                                        ? '1 day'
                                                        : `${days} days`}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    `${pitr.keepDays} days`
                                )}
                            </dd>
                        </div>
                    </dl>
                </>
            )}
        </div>
    );
}

/**
 * The newest restorable point in words. While shipping is active nothing
 * waits but the segment Postgres is still filling (switched at least every
 * minute), so it is "about a minute ago"; otherwise null (use lastArchivedAt).
 */
export function windowEndText(pitr: PointInTime): string | null {
    return pitr.state === 'active' && pitr.pendingSegments === 0
        ? 'about a minute ago'
        : null;
}

/** `YYYY-MM-DDTHH:mm:ss` in local time, for a datetime-local input. */
export function toLocalInput(iso: string): string {
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

/**
 * The time picker under the "Any point in time" timeline entry: a local
 * date-time (seconds) inside the window, or "newest" for as far as WAL goes.
 */
export function PointInTimePicker({
    pitr,
    value,
    onChange,
}: {
    pitr: PointInTime;
    value: string | null;
    onChange: (value: string | null) => void;
}) {
    if (pitr.windowStart === null || pitr.windowEnd === null) {
        return null;
    }

    return (
        <div className="ml-10 flex flex-wrap items-center gap-2 pb-2">
            <input
                type="datetime-local"
                step={1}
                aria-label="Restore to"
                className="h-8 rounded-md border bg-background px-2 type-mono-small focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                min={toLocalInput(pitr.windowStart)}
                max={toLocalInput(new Date().toISOString())}
                value={value ?? toLocalInput(pitr.windowEnd)}
                onChange={(event) =>
                    onChange(
                        event.target.value === ''
                            ? null
                            : new Date(event.target.value).toISOString(),
                    )
                }
            />
            <Button
                size="sm"
                variant={value === null ? 'secondary' : 'ghost'}
                onClick={() => onChange(null)}
            >
                Newest
            </Button>
        </div>
    );
}
