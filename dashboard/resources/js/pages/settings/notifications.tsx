import { Head, Link, router } from '@inertiajs/react';
import { BellOff, KeyRound, Plus } from 'lucide-react';
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
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { edit } from '@/routes/notifications';
import { edit as teamSettings } from '@/routes/team';

type Props = {
    channels: App.Data.Alerts.AlertChannelData[];
    rules: App.Data.Alerts.AlertRuleData[];
    openAlerts: App.Data.Alerts.AlertSummaryData[];
    canManage: boolean;
    mailConfigured: boolean;
    defaultEmail: string;
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

function OpenAlerts({
    alerts,
}: {
    alerts: App.Data.Alerts.AlertSummaryData[];
}) {
    if (alerts.length === 0) {
        return null;
    }

    return (
        <SettingsCard
            title="Open now"
            description={`${alerts.length} ${alerts.length === 1 ? 'problem is' : 'problems are'} still going on.`}
        >
            {alerts.map((alert) => (
                <SettingsRow
                    key={alert.id}
                    media={
                        <span className="flex size-8 items-center justify-center">
                            <StatusIcon
                                state={
                                    alert.severity === 'critical'
                                        ? 'failed'
                                        : 'warning'
                                }
                            />
                        </span>
                    }
                    label={alert.title}
                    description={
                        <span>
                            {alert.summary} Since{' '}
                            <TimeAgo value={alert.openedAt} />.
                        </span>
                    }
                    align="start"
                >
                    {alert.href && (
                        <Button asChild size="sm" variant="ghost">
                            <Link href={alert.href}>Look</Link>
                        </Button>
                    )}
                </SettingsRow>
            ))}
        </SettingsCard>
    );
}

/**
 * Settings → Notifications (docs/15 §3.6): what's open, where the team
 * hears about alerts (a guided "Where should we tell you?" until the first
 * channel exists) and what it hears about.
 */
export default function NotificationSettings({
    channels,
    rules,
    openAlerts,
    canManage,
    mailConfigured,
    defaultEmail,
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

            <OpenAlerts alerts={openAlerts} />

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
                        Nobody is told about problems yet. Owners and admins
                        choose where alerts go.
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

NotificationSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        { title: 'Notifications', href: edit() },
    ],
};
