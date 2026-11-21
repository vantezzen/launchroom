import { router } from '@inertiajs/react';
import {
    ArrowUpRight,
    CircleArrowUp,
    CircleCheck,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SettingsCard, SettingsRow } from '@/components/settings/settings-card';
import { relativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { vocabulary } from '@/lib/vocabulary';
import update from '@/routes/projects/services/update';
import { availableLine, kindLabel, publishedWords, runStep } from './format';
import type { ServiceUpdate } from './format';
import { UpdateDialog } from './update-dialog';

type Mode = App.Enums.AppUpdates.AutoUpdateMode;
type Window = App.Data.AppUpdates.UpdateWindowData;

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const HOURS = [1, 2, 3, 4, 6, 8, 12];
const MODES: Mode[] = ['off', 'patch', 'minor'];

function browserZone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

function defaultWindow(): Window {
    return {
        days: [1, 2, 3, 4, 5, 6, 7],
        start: '03:00',
        hours: 3,
        timezone: browserZone(),
    };
}

/**
 * Settings → Source → Updates for image services (docs/04 §14.4): what is
 * available (or that it is current, with Check now), whether launchroom
 * installs patch or minor updates by itself, and when. Saved at once: it
 * changes no running version, so it isn't staged.
 */
export function UpdatesCard({ service }: { service: ServiceUpdate }) {
    const [open, setOpen] = useState(false);
    const route = {
        project: service.projectId,
        environment: service.environmentId,
        service: service.serviceId,
    };
    const saveAuto = (mode: Mode, window: Window) =>
        router.put(
            update.auto(route).url,
            { mode, window },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <SettingsCard
            id="updates"
            setting="updates"
            title="Updates"
            description="launchroom looks for newer releases of this image twice a day."
        >
            <StatusRow
                service={service}
                onUpdate={() => setOpen(true)}
                onCheck={() =>
                    router.post(update.check(route).url, undefined, {
                        preserveScroll: true,
                    })
                }
            />
            <SettingsRow
                setting="auto-update"
                label="Update automatically"
                description={
                    service.autoBlocker ??
                    'Never major updates. Backs up first, waits until a release is 2 days old and rolls back if it keeps crashing.'
                }
            >
                <Select
                    value={service.autoUpdate}
                    disabled={
                        !service.canUpdate || service.autoBlocker !== null
                    }
                    onValueChange={(mode) =>
                        saveAuto(
                            mode as Mode,
                            service.window ?? defaultWindow(),
                        )
                    }
                >
                    <SelectTrigger
                        className="w-[220px]"
                        aria-label="Update automatically"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {MODES.map((mode) => (
                            <SelectItem key={mode} value={mode}>
                                {
                                    vocabulary.AppUpdates.AutoUpdateMode[mode]
                                        .label
                                }
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </SettingsRow>
            {service.autoUpdate !== 'off' && (
                <WindowRow
                    key={JSON.stringify(service.window)}
                    window={service.window ?? defaultWindow()}
                    disabled={!service.canUpdate}
                    onSave={(window) => saveAuto(service.autoUpdate, window)}
                />
            )}
            {service.canUpdate && service.recommended && (
                <UpdateDialog
                    service={service}
                    open={open}
                    onOpenChange={setOpen}
                />
            )}
        </SettingsCard>
    );
}

function StatusRow({
    service,
    onUpdate,
    onCheck,
}: {
    service: ServiceUpdate;
    onUpdate: () => void;
    onCheck: () => void;
}) {
    const candidate = service.recommended;
    const checked = service.checkedAt
        ? `Checked ${relativeTime(service.checkedAt)}.`
        : 'Not checked yet.';
    const published = publishedWords(service.runningPublishedAt);
    const running = service.running
        ? `You run ${service.running}${published ? `, ${published}` : ''}.`
        : null;

    if (service.run && service.run.status !== 'succeeded') {
        return (
            <SettingsRow
                label={runStep(service.run)}
                description={
                    service.run.message ?? service.run.backupNote ?? running
                }
            />
        );
    }

    if (candidate) {
        return (
            <SettingsRow
                media={<CircleArrowUp aria-hidden="true" className="size-4" />}
                label={availableLine(candidate)}
                description={[
                    `${kindLabel(candidate.kind)}.`,
                    running,
                    service.major && candidate.kind !== 'major'
                        ? `${service.major.version} is out too.`
                        : null,
                ]
                    .filter(Boolean)
                    .join(' ')}
            >
                {service.releaseNotesUrl && (
                    <Button variant="ghost" size="sm" asChild>
                        <a
                            href={service.releaseNotesUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Release notes
                            <ArrowUpRight aria-hidden="true" />
                        </a>
                    </Button>
                )}
                {service.canUpdate && (
                    <Button size="sm" onClick={onUpdate}>
                        {candidate.kind === 'major'
                            ? 'Review update'
                            : 'Update'}
                    </Button>
                )}
            </SettingsRow>
        );
    }

    const label =
        service.status === 'current'
            ? 'Up to date'
            : service.status === 'failed'
              ? 'Couldn’t check for updates'
              : 'No version to compare';

    return (
        <SettingsRow
            media={
                service.status === 'current' ? (
                    <CircleCheck
                        aria-hidden="true"
                        className="size-4 text-status-ok"
                    />
                ) : undefined
            }
            label={label}
            description={[
                service.status === 'current' ? running : service.reason,
                checked,
            ]
                .filter(Boolean)
                .join(' ')}
        >
            {service.canUpdate && service.checkable && (
                <Button variant="outline" size="sm" onClick={onCheck}>
                    <RefreshCw aria-hidden="true" />
                    Check now
                </Button>
            )}
        </SettingsRow>
    );
}

/** Days, start and length of the maintenance window, in the window's time zone. */
function WindowRow({
    window,
    disabled,
    onSave,
}: {
    window: Window;
    disabled: boolean;
    onSave: (window: Window) => void;
}) {
    const [draft, setDraft] = useState<Window>(window);
    const dirty = JSON.stringify(draft) !== JSON.stringify(window);
    const toggleDay = (day: number) =>
        setDraft((current) => {
            const days = current.days.includes(day)
                ? current.days.filter((d) => d !== day)
                : [...current.days, day].sort((a, b) => a - b);

            return days.length === 0 ? current : { ...current, days };
        });

    return (
        <SettingsRow
            setting="maintenance-window"
            label="Maintenance window"
            description={`Updates start only inside it. Times in ${draft.timezone}.`}
            align="start"
        >
            <div className="flex flex-col items-start gap-3 sm:items-end">
                <div
                    role="group"
                    aria-label="Days"
                    className="flex flex-wrap gap-1"
                >
                    {DAYS.map((label, index) => {
                        const day = index + 1;
                        const on = draft.days.includes(day);

                        return (
                            <button
                                key={label}
                                type="button"
                                disabled={disabled}
                                aria-pressed={on}
                                onClick={() => toggleDay(day)}
                                className={cn(
                                    'h-8 w-11 rounded-md border type-label transition-colors duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60',
                                    on
                                        ? 'border-foreground bg-foreground text-background'
                                        : 'text-muted-foreground hover:bg-hover',
                                )}
                            >
                                {label}
                            </button>
                        );
                    })}
                </div>
                <div className="flex flex-wrap items-center gap-2 type-small text-muted-foreground">
                    <span>From</span>
                    <Select
                        value={draft.start}
                        disabled={disabled}
                        onValueChange={(start) =>
                            setDraft((current) => ({ ...current, start }))
                        }
                    >
                        <SelectTrigger
                            className="h-8 w-[92px] font-mono"
                            aria-label="Start"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Array.from({ length: 24 }, (_, hour) => {
                                const value = `${String(hour).padStart(2, '0')}:00`;

                                return (
                                    <SelectItem key={value} value={value}>
                                        {value}
                                    </SelectItem>
                                );
                            })}
                        </SelectContent>
                    </Select>
                    <span>for</span>
                    <Select
                        value={String(draft.hours)}
                        disabled={disabled}
                        onValueChange={(hours) =>
                            setDraft((current) => ({
                                ...current,
                                hours: Number(hours),
                            }))
                        }
                    >
                        <SelectTrigger
                            className="h-8 w-[110px]"
                            aria-label="Length"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {HOURS.map((hours) => (
                                <SelectItem key={hours} value={String(hours)}>
                                    {hours === 1 ? '1 hour' : `${hours} hours`}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {dirty && (
                        <Button
                            size="sm"
                            className="h-8"
                            disabled={disabled}
                            onClick={() => onSave(draft)}
                        >
                            Save
                        </Button>
                    )}
                </div>
            </div>
        </SettingsRow>
    );
}
