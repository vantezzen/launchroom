import { Head, Link, router } from '@inertiajs/react';
import { BellOff, ChevronRight, KeyRound, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CopyValue } from '@/components/copy-value';
import { EmptyState } from '@/components/patterns/empty-state';
import { Section } from '@/components/patterns/section';
import { SidePanel, SheetSection } from '@/components/patterns/sheet';
import { StatusIcon } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { AddChannel } from '@/features/alerts/add-channel';
import { ChannelRow } from '@/features/alerts/channel-row';
import { RulesCard } from '@/features/alerts/rules-card';
import { SettingsCard, SettingsRow } from '@/components/settings/settings-card';
import SettingsLayout from '@/features/settings/settings-layout';
import { index as activityIndex } from '@/routes/activity';

type Props = {
    channels: App.Data.Alerts.AlertChannelData[];
    rules: App.Data.Alerts.AlertRuleData[];
    openAlerts: App.Data.Alerts.AlertSummaryData[];
    openAlertCount: number;
    canManage: boolean;
    mailConfigured: boolean;
    defaultEmail: string;
    /** Instance owners of the main server's team: the fallback channel (OD7). */
    canSetFallback: boolean;
    fallbackEmailAvailable: boolean;
};

type Kind = App.Enums.Alerts.AlertChannelKind;

/** Flash data that arrives once after adding a channel. */
function useFlash<T>(key: string): T | null {
    const [value, setValue] = useState<T | null>(null);

    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash as
                    | Record<string, unknown>
                    | undefined;

                if (flash && key in flash) {
                    setValue(flash[key] as T);
                }
            }),
        [key],
    );

    return value;
}

/**
 * Open alerts live in Activity (docs/15 §3.6): here one row says how many
 * are open with the two most severe under it, and links there.
 */
function OpenAlerts({
    alerts,
    count,
}: {
    alerts: App.Data.Alerts.AlertSummaryData[];
    count: number;
}) {
    if (count === 0) {
        return null;
    }

    const href = activityIndex({
        query: { kind: 'alerts', state: 'open' },
    }).url;
    const critical = alerts.some((alert) => alert.severity === 'critical');

    return (
        <section
            aria-label="Open alerts"
            className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card"
        >
            <Link
                href={href}
                className="flex min-h-14 items-center gap-3 px-4 py-3 hover:bg-(--hover)/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)"
            >
                <StatusIcon state={critical ? 'failed' : 'warning'} />
                <span className="flex-1 type-body font-medium">
                    {count === 1
                        ? '1 problem is open'
                        : `${count} problems are open`}
                </span>
                <span className="inline-flex items-center gap-1 type-small text-muted-foreground">
                    View in Activity
                    <ChevronRight aria-hidden="true" className="size-4" />
                </span>
            </Link>
            <ul className="divide-y border-t">
                {alerts.map((alert) => {
                    const row = (
                        <>
                            <StatusIcon
                                state={
                                    alert.severity === 'critical'
                                        ? 'failed'
                                        : 'warning'
                                }
                            />
                            <span className="min-w-0 flex-1 truncate type-body">
                                {alert.title}
                            </span>
                            <span className="shrink-0 type-small text-muted-foreground">
                                <TimeAgo value={alert.openedAt} />
                            </span>
                            {alert.href && (
                                <ChevronRight
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-muted-foreground"
                                />
                            )}
                        </>
                    );
                    const className =
                        'flex min-h-11 items-center gap-3 px-4 py-2';

                    return (
                        <li key={alert.id}>
                            {alert.href ? (
                                <Link
                                    href={alert.href}
                                    className={`${className} hover:bg-(--hover)/50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--signal)`}
                                >
                                    {row}
                                </Link>
                            ) : (
                                <div className={className}>{row}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/**
 * Settings → Notifications (docs/15 §3.6): how many alerts are open (they
 * live in Activity), where the team hears about alerts (a guided "Where should we tell you?" until the first
 * channel exists) and what it hears about.
 */
export default function NotificationSettings({
    channels,
    rules,
    openAlerts,
    openAlertCount,
    canManage,
    mailConfigured,
    defaultEmail,
    canSetFallback,
    fallbackEmailAvailable,
}: Props) {
    const [adding, setAdding] = useState(false);
    const [kind, setKind] = useState<Kind | null>(null);
    const created = useFlash<string>('alertChannelCreated');
    const secret = useFlash<{ channel: string; secret: string }>(
        'alertSigningSecret',
    );

    return (
        <>
            <Head title="Notifications" />

            <OpenAlerts alerts={openAlerts} count={openAlertCount} />

            {secret && (
                <SettingsCard
                    title="Signing secret"
                    description="Store it in your receiver now. It won't be shown again."
                >
                    <SettingsRow
                        label="Verify each request with it"
                        media={
                            <span className="flex size-8 items-center justify-center rounded-md border bg-card text-muted-foreground">
                                <KeyRound
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </span>
                        }
                        description={
                            <span className="type-mono-small break-all text-foreground">
                                {secret.secret}
                            </span>
                        }
                        align="start"
                    >
                        <CopyValue
                            value={secret.secret}
                            label="signing secret"
                        />
                    </SettingsRow>
                </SettingsCard>
            )}

            {channels.length === 0 ? (
                canManage ? (
                    <Section
                        title="Where should we tell you?"
                        description="Pick one place to start. You can add more later."
                    >
                        <div className="rounded-xl border bg-card p-5 shadow-card">
                            <AddChannel
                                kind={kind}
                                onKindChange={setKind}
                                defaultEmail={defaultEmail}
                                mailConfigured={mailConfigured}
                            />
                        </div>
                    </Section>
                ) : (
                    <EmptyState icon={BellOff}>
                        Alerts aren’t sent anywhere yet. Owners and admins can
                        choose where they go.
                    </EmptyState>
                )
            ) : (
                <SettingsCard
                    title="Where we tell you"
                    description="Every alert goes to each place below."
                    actions={
                        canManage && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setKind(null);
                                    setAdding(true);
                                }}
                            >
                                <Plus aria-hidden="true" />
                                Add another
                            </Button>
                        )
                    }
                >
                    {channels.map((channel) => (
                        <ChannelRow
                            key={channel.id}
                            channel={channel}
                            fallback={{
                                canSet: canSetFallback,
                                emailAvailable: fallbackEmailAvailable,
                            }}
                            highlight={
                                channel.id === created &&
                                channel.lastDeliveryAt === null
                            }
                        />
                    ))}
                </SettingsCard>
            )}

            <RulesCard rules={rules} canManage={canManage} />

            <SidePanel
                open={adding}
                onOpenChange={setAdding}
                size="md"
                title="Add a place to tell you"
                description="Alerts go to every place you add."
            >
                <SheetSection>
                    <AddChannel
                        kind={kind}
                        onKindChange={setKind}
                        defaultEmail={defaultEmail}
                        mailConfigured={mailConfigured}
                        onAdded={() => setAdding(false)}
                    />
                </SheetSection>
            </SidePanel>
        </>
    );
}

NotificationSettings.layout = SettingsLayout;
