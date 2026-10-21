import { router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useState } from 'react';
import { Logo } from '@/components/logo';
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
import { SettingsRow } from '@/features/settings/settings-card';
import { destroy, test, update } from '@/routes/notifications/channels';

type Channel = App.Data.Alerts.AlertChannelData;

function Delivery({ channel }: { channel: Channel }) {
    if (!channel.enabled) {
        return <Status state="idle" label="Paused" />;
    }

    if (channel.lastDeliveryOk === false) {
        return (
            <span className="flex flex-col gap-0.5">
                <Status state="failed" label="The last message didn't arrive" />
                {channel.lastError && (
                    <span className="pl-5 break-words">
                        {channel.lastError}
                    </span>
                )}
            </span>
        );
    }

    if (channel.lastDeliveryAt) {
        return (
            <span>
                Delivered <TimeAgo value={channel.lastDeliveryAt} />
                {channel.minSeverity === 'critical' &&
                    ' · critical alerts only'}
            </span>
        );
    }

    return (
        <span>
            Nothing sent yet
            {channel.minSeverity === 'critical' && ' · critical alerts only'}
        </span>
    );
}

/**
 * One channel (docs/15 §3.6): what it is and where it goes, how the last
 * message went, "Send test" as the visible action and the rest behind ⋯
 * (remove confirmed in a popover).
 */
export function ChannelRow({
    channel,
    highlight = false,
}: {
    channel: Channel;
    /** Just added: the test button is the next step. */
    highlight?: boolean;
}) {
    const [confirming, setConfirming] = useState(false);
    const [testing, setTesting] = useState(false);
    const target =
        channel.kind === 'email'
            ? channel.recipients.join(', ')
            : (channel.hint ?? '');
    const patch = (data: Partial<App.Data.Alerts.AlertChannelUpdateInput>) =>
        router.patch(update(channel.id).url, data, { preserveScroll: true });

    return (
        <SettingsRow
            label={channel.name}
            media={<Logo kind={channel.kind} size={32} name={channel.kind} />}
            description={
                <span className="flex flex-col gap-1">
                    {target !== '' && target !== channel.name && (
                        <span className="truncate type-mono-small">
                            {target}
                        </span>
                    )}
                    <Delivery channel={channel} />
                </span>
            }
            align="start"
        >
            {channel.canManage && (
                <>
                    <Button
                        size="sm"
                        variant={highlight ? 'default' : 'outline'}
                        disabled={testing}
                        onClick={() =>
                            router.post(
                                test(channel.id).url,
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
                                        aria-label={`Options for ${channel.name}`}
                                    >
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                            </PopoverAnchor>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onSelect={() =>
                                        patch({ enabled: !channel.enabled })
                                    }
                                >
                                    {channel.enabled ? 'Pause' : 'Resume'}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() =>
                                        patch({
                                            minSeverity:
                                                channel.minSeverity ===
                                                'critical'
                                                    ? 'warning'
                                                    : 'critical',
                                        })
                                    }
                                >
                                    {channel.minSeverity === 'critical'
                                        ? 'Send every alert'
                                        : 'Only critical alerts'}
                                </DropdownMenuItem>
                                {channel.kind === 'webhook' && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            patch({ rotateSecret: true })
                                        }
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
                                <p className="type-label">
                                    Remove {channel.name}?
                                </p>
                                <p className="type-body text-muted-foreground">
                                    Alerts stop going here. Other channels keep
                                    getting them.
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
                                        router.delete(destroy(channel.id).url, {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setConfirming(false),
                                        })
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        </PopoverContent>
                    </Popover>
                </>
            )}
        </SettingsRow>
    );
}
