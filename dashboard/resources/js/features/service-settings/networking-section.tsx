import { ArrowRight, Globe, Lock, Network, Plug } from 'lucide-react';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { ServiceGlyph } from '@/features/canvas/service-glyph';
import { CopyButton } from './general-section';
import { IconTile, SettingsPage } from './section';
import type { SettingsContext } from './settings-context';
import type { Navigate } from './settings-tab';

/**
 * Private network: the address other services of this environment use,
 * drawn as the path it is (your services → this one), then where its
 * pieces come from. Public traffic lives under Domains.
 */
export function NetworkingSection({
    context,
    navigate,
}: {
    context: SettingsContext;
    navigate: Navigate;
}) {
    const { settings, node } = context;
    const web = node.kind === 'app' || node.kind === 'image';
    const port = settings.deploy.port ?? settings.detected?.port ?? null;
    const address =
        node.privateAddress ??
        (port !== null
            ? `${web ? 'http://' : ''}${settings.privateHost}:${port}`
            : settings.privateHost);

    return (
        <SettingsPage
            title="Private network"
            description="Other services in this environment reach it here. It never leaves your servers."
        >
            <div
                data-setting="private-address"
                className="overflow-hidden rounded-xl border bg-card shadow-card"
            >
                <div className="flex flex-col items-center gap-4 bg-recessed px-5 py-8 @xl/settings:flex-row @xl/settings:justify-center @xl/settings:gap-3">
                    <span className="flex items-center gap-2 rounded-xl border bg-card px-3 py-2 type-small text-muted-foreground shadow-card">
                        <Network aria-hidden="true" className="size-4" />
                        Your other services
                    </span>
                    <span
                        aria-hidden="true"
                        className="flex items-center text-faint @xl/settings:w-16"
                    >
                        <span className="hidden h-px flex-1 border-t border-dashed border-signal @xl/settings:block" />
                        <ArrowRight className="size-4 rotate-90 text-signal @xl/settings:rotate-0" />
                    </span>
                    <span className="flex min-w-0 items-center gap-3 rounded-xl border bg-card py-2 pr-2 pl-3 shadow-card">
                        <ServiceGlyph logo={node.logo} size="sm" />
                        <code className="min-w-0 truncate type-mono font-medium">
                            {address}
                        </code>
                        <CopyButton value={address} label="private address" />
                    </span>
                </div>
                <div className="flex items-center gap-2 border-t px-5 py-3 type-small text-muted-foreground">
                    <Lock aria-hidden="true" className="size-3.5" />
                    Stays on your servers’ private network. The internet can’t
                    reach it.
                </div>
            </div>

            <SettingsCard>
                <SettingsRow
                    media={
                        <IconTile>
                            <Network />
                        </IconTile>
                    }
                    label="Hostname"
                    description="The service's slug. It never changes."
                >
                    <code className="type-mono text-muted-foreground">
                        {settings.privateHost}
                    </code>
                </SettingsRow>
                {port !== null && (
                    <SettingsRow
                        media={
                            <IconTile>
                                <Plug />
                            </IconTile>
                        }
                        label="Port"
                        description={
                            settings.deploy.port !== null
                                ? 'Set under Deploy.'
                                : 'Detected from your app.'
                        }
                    >
                        <code className="type-mono text-muted-foreground">
                            {port}
                        </code>
                        {node.kind !== 'database' && (
                            <button
                                type="button"
                                onClick={() => navigate('deploy')}
                                className="type-small text-signal underline-offset-4 hover:underline"
                            >
                                Change
                            </button>
                        )}
                    </SettingsRow>
                )}
                {web && (
                    <SettingsRow
                        media={
                            <IconTile>
                                <Globe />
                            </IconTile>
                        }
                        label="Public"
                        description={
                            settings.domains.length === 0
                                ? 'Not on the internet yet.'
                                : `On the internet through ${settings.domains.length} ${settings.domains.length === 1 ? 'domain' : 'domains'}.`
                        }
                    >
                        <button
                            type="button"
                            onClick={() => navigate('domains')}
                            className="type-small text-signal underline-offset-4 hover:underline"
                        >
                            Domains
                        </button>
                    </SettingsRow>
                )}
            </SettingsCard>
        </SettingsPage>
    );
}
