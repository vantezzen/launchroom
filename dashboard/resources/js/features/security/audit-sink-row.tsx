import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Status } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { SINK_KINDS } from '@/features/security/add-audit-sink';
import { SettingsRow } from '@/features/settings/settings-card';
import { destroy, retry, test, update } from '@/routes/audit-sinks';

type Sink = App.Data.Security.AuditSinkData;

const entries = (n: number) =>
    `${n.toLocaleString()} ${n === 1 ? 'entry' : 'entries'}`;

/** Delivery state as icon + words (design-system §3.3). */
function Delivery({ sink }: { sink: Sink }) {
    if (sink.status === 'paused') {
        return (
            <Status
                state="idle"
                label="Paused"
                detail={
                    sink.behind > 0
                        ? `${entries(sink.behind)} waiting`
                        : undefined
                }
            />
        );
    }

    if (sink.status === 'failing') {
        return (
            <span className="flex flex-col gap-0.5">
                <Status
                    state="failed"
                    label="Not delivering"
                    detail={`behind by ${entries(sink.behind)}`}
                />
                {sink.lastError && (
                    <span className="pl-5 break-words">{sink.lastError}</span>
                )}
                {sink.nextAttemptAt && (
                    <span className="pl-5">
                        {new Date(sink.nextAttemptAt).getTime() > Date.now() ? (
                            <>
                                Trying again{' '}
                                <TimeAgo value={sink.nextAttemptAt} />
                            </>
                        ) : (
                            'Trying again now'
                        )}
                    </span>
                )}
            </span>
        );
    }

    if (sink.status === 'pending') {
        return (
            <Status
                state="progress"
                label="Starting"
                detail={`${entries(sink.behind)} to send`}
            />
        );
    }

    return (
        <span className="flex flex-col gap-0.5">
            <Status
                state={sink.behind > 0 ? 'progress' : 'active'}
                label={sink.behind > 0 ? 'Catching up' : 'Up to date'}
                detail={
                    sink.behind > 0
                        ? `behind by ${entries(sink.behind)}`
                        : undefined
                }
            />
            {sink.deliveredThroughAt && (
                <span className="pl-5">
                    Delivered up to <TimeAgo value={sink.deliveredThroughAt} />
                </span>
            )}
        </span>
    );
}

/**
 * One audit log destination (docs/09 R34): where it goes, how delivery is
 * going, "Send test" as the visible action and the rest behind ⋯.
 */
export function AuditSinkRow({ sink }: { sink: Sink }) {
    const [confirming, setConfirming] = useState(false);
    const [testing, setTesting] = useState(false);
    const kind = SINK_KINDS.find((entry) => entry.value === sink.kind);
    const patch = (data: Partial<App.Data.Audit.AuditSinkUpdateInput>) =>
        router.patch(update(sink.id).url, data, { preserveScroll: true });
    const lock =
        sink.kind === 's3' && sink.objectLock !== null
            ? sink.objectLock && sink.retentionDays
                ? `Locked ${sink.retentionDays} days (${sink.lockMode?.toLowerCase()})`
                : sink.objectLock
                  ? 'Object Lock available, not used'
                  : 'No Object Lock on this bucket'
            : null;

    return (
        <SettingsRow
            label={sink.name}
            media={kind?.media}
            description={
                <span className="flex flex-col gap-1">
                    <span className="truncate type-mono-small">
                        {[
                            sink.target,
                            lock,
                            sink.privateNetwork ? 'own network' : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                    <Delivery sink={sink} />
                </span>
            }
            align="start"
        >
            <Button
                size="sm"
                variant="outline"
                disabled={testing}
                onClick={() =>
                    router.post(
                        test(sink.id).url,
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setTesting(true),
                            onFinish: () => setTesting(false),
                        },
                    )
                }
            >
                {testing ? 'Sending…' : 'Send test'}
            </Button>
            <Popover open={confirming} onOpenChange={setConfirming}>
                <DropdownMenu modal={false}>
                    <PopoverAnchor asChild>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Options for ${sink.name}`}
                            >
                                <MoreHorizontal aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                    </PopoverAnchor>
                    <DropdownMenuContent align="end">
                        {sink.status === 'failing' && (
                            <DropdownMenuItem
                                onSelect={() =>
                                    router.post(
                                        retry(sink.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Retry now
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onSelect={() =>
                                patch({ enabled: sink.status === 'paused' })
                            }
                        >
                            {sink.status === 'paused' ? 'Resume' : 'Pause'}
                        </DropdownMenuItem>
                        {sink.kind === 'webhook' && (
                            <DropdownMenuItem
                                onSelect={() => patch({ rotateSecret: true })}
                            >
                                New signing secret
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            className="text-status-error focus:text-status-error"
                            onSelect={() => setConfirming(true)}
                        >
                            Remove…
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <PopoverContent
                    align="end"
                    className="flex w-80 flex-col gap-3"
                >
                    <div className="flex flex-col gap-1">
                        <p className="type-label">Remove {sink.name}?</p>
                        <p className="type-body text-muted-foreground">
                            New entries stop going here. What it already
                            received stays there.
                        </p>
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() =>
                                router.delete(destroy(sink.id).url, {
                                    preserveScroll: true,
                                    onFinish: () => setConfirming(false),
                                })
                            }
                        >
                            Remove
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </SettingsRow>
    );
}
