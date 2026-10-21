import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { ConfirmAction } from '@/components/confirm-action';
import { CopyValue } from '@/components/copy-value';
import InputError from '@/components/input-error';
import { ChoiceTiles } from '@/components/patterns/choice-tiles';
import { AddGitServerForm } from '@/features/git/add-git-server-form';
import { ConnectProviderForm } from '@/features/git/connect-provider-form';
import type { ConnectPreset } from '@/features/git/connect-provider-form';
import { CreateGitHubAppForm } from '@/features/git/create-github-app-form';
import { GitConnectionCard } from '@/features/git/git-connection-card';
import { GitServerSetup } from '@/features/git/git-server-setup';
import { GitHubAppSection } from '@/features/git/github-app-section';
import { ManifestRedirect } from '@/features/git/manifest-redirect';
import { PROVIDER_CHOICES } from '@/features/git/providers';
import type { ProviderChoice } from '@/features/git/providers';
import { WebhookReachabilityNote } from '@/features/git/webhook-reachability-note';
import {
    LearnMore,
    SettingsBody,
    SettingsCard,
    SettingsRow,
    useDocsUrl,
} from '@/features/settings/settings-card';
import { edit } from '@/routes/git';
import { destroy as destroyConnection } from '@/routes/git/connections';
import { destroy } from '@/routes/git/github';
import { edit as teamSettings } from '@/routes/team';

type Props = {
    apps: App.Data.Git.GitHubAppData[];
    connections: App.Data.Git.GitConnectionData[];
    canManage: boolean;
    pendingManifest: App.Data.Git.ManifestRedirectData | null;
    webhookUrl: string;
    webhookReachable: boolean;
    setup: string | null;
    oauthCallbackUrl: string;
    gitlabUrl: string;
    canAllowPrivateNetwork: boolean;
};

/**
 * Settings → Git (docs/19 §4, docs/design/flows.md §2): "Where does your
 * code live?" as four provider tiles with one guided flow each, then one
 * card per connection with a single health line.
 */
export default function GitSettings({
    apps,
    connections,
    canManage,
    pendingManifest,
    webhookUrl,
    webhookReachable,
    setup,
    oauthCallbackUrl,
    gitlabUrl,
    canAllowPrivateNetwork,
}: Props) {
    const docsUrl = useDocsUrl();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const connected = apps.length > 0 || connections.length > 0;
    const [choice, setChoice] = useState<ProviderChoice | null>(null);
    const [preset, setPreset] = useState<ConnectPreset | undefined>();
    const pickerRef = useRef<HTMLDivElement>(null);
    const setupConnection =
        connections.find(
            (connection) =>
                connection.id === setup &&
                connection.provider === 'git' &&
                (connection.status === 'pending' ||
                    !connection.hostKeysConfirmed),
        ) ?? null;

    const choose = (next: ProviderChoice) => {
        setPreset(undefined);
        setChoice(next);
    };

    const signInAgain = (connection: App.Data.Git.GitConnectionData) => {
        setPreset({
            baseUrl: connection.url,
            allowPrivateNetwork: connection.allowPrivateNetwork,
            method: connection.authMethod === 'token' ? 'token' : 'oauth',
        });
        setChoice(connection.provider === 'gitlab' ? 'gitlab' : 'gitea');
        requestAnimationFrame(() =>
            pickerRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            }),
        );
    };

    const picker = canManage && pendingManifest === null && (
        <section
            ref={pickerRef}
            aria-labelledby="git-picker"
            className="flex scroll-mt-6 flex-col gap-6 rounded-xl border bg-card p-6 shadow-card"
        >
            <div className="flex max-w-xl flex-col gap-1">
                <h2 id="git-picker" className="type-heading">
                    {connected
                        ? 'Connect another place'
                        : 'Where does your code live?'}
                </h2>
                <p className="type-body text-muted-foreground">
                    launchroom reads your code to build it and tells your git
                    host how each deploy went. Nothing else.
                </p>
            </div>
            <ChoiceTiles<ProviderChoice>
                label="Git provider"
                options={PROVIDER_CHOICES}
                value={choice}
                onChange={choose}
                columns={4}
            />
            {choice === 'github' && (
                <div className="flex animate-enter flex-col gap-4">
                    <p className="max-w-prose type-body text-muted-foreground">
                        launchroom deploys through a GitHub App your team owns.
                        GitHub creates it with the permissions launchroom needs
                        and you pick the repositories it can read.
                    </p>
                    {!webhookReachable && (
                        <div className="max-w-xl">
                            <WebhookReachabilityNote webhookUrl={webhookUrl} />
                        </div>
                    )}
                    <CreateGitHubAppForm primary={apps.length === 0} />
                </div>
            )}
            {(choice === 'gitlab' || choice === 'gitea') && (
                <ConnectProviderForm
                    key={`${choice}-${preset?.baseUrl ?? ''}`}
                    provider={choice}
                    gitlabUrl={gitlabUrl}
                    oauthCallbackUrl={oauthCallbackUrl}
                    canAllowPrivateNetwork={canAllowPrivateNetwork}
                    preset={preset}
                />
            )}
            {choice === 'git' && (
                <AddGitServerForm
                    canAllowPrivateNetwork={canAllowPrivateNetwork}
                />
            )}
        </section>
    );

    const removable = [
        ...apps
            .filter((app) => app.canManage)
            .map((app) => ({ kind: 'app' as const, app })),
        ...connections
            .filter((connection) => connection.canManage)
            .map((connection) => ({ kind: 'connection' as const, connection })),
    ];

    return (
        <>
            <Head title="Git" />

            <InputError message={errors.github ?? errors.git} />

            {pendingManifest !== null && (
                <SettingsCard title="GitHub">
                    <SettingsBody>
                        <ManifestRedirect manifest={pendingManifest} />
                    </SettingsBody>
                </SettingsCard>
            )}

            {setupConnection !== null && canManage && (
                <GitServerSetup connection={setupConnection} />
            )}

            {!connected && !canManage && (
                <section className="flex flex-col items-center gap-2 rounded-xl border bg-card px-6 py-10 text-center shadow-card">
                    <h2 className="type-heading">No git provider yet</h2>
                    <p className="max-w-md type-body text-muted-foreground">
                        Ask a team owner or admin to connect GitHub, GitLab,
                        Gitea or your own git server.
                    </p>
                </section>
            )}

            {!connected && picker}

            {connected && (
                <section className="flex flex-col gap-3.5">
                    <h2 className="type-heading">Connected</h2>
                    {apps.map((app) => (
                        <GitHubAppSection key={app.id} app={app} />
                    ))}
                    {connections
                        .filter(
                            (connection) =>
                                connection.id !== setupConnection?.id,
                        )
                        .map((connection) => (
                            <GitConnectionCard
                                key={connection.id}
                                connection={connection}
                                onSignInAgain={signInAgain}
                            />
                        ))}
                </section>
            )}

            {connected && picker}

            {apps.length > 0 && (
                <SettingsCard
                    title="Advanced"
                    footer={
                        <>
                            Without webhooks, launchroom checks tracked branches
                            every minute.{' '}
                            <LearnMore href={docsUrl('builds/git-providers')}>
                                How pushes arrive
                            </LearnMore>
                        </>
                    }
                >
                    <SettingsRow
                        label="GitHub webhook URL"
                        description="GitHub sends pushes and pull requests here."
                        align="start"
                    >
                        <CopyValue
                            value={webhookUrl}
                            label="webhook URL"
                            className="w-full sm:w-80"
                        />
                    </SettingsRow>
                    {canManage && pendingManifest === null && (
                        <SettingsRow
                            label="Another GitHub App"
                            description="For a second organization or a GitHub Enterprise Server."
                            align="start"
                        >
                            <CreateGitHubAppForm primary={false} />
                        </SettingsRow>
                    )}
                </SettingsCard>
            )}

            {removable.length > 0 && (
                <SettingsCard title="Danger zone">
                    {removable.map((item) =>
                        item.kind === 'app' ? (
                            <SettingsRow
                                key={item.app.id}
                                label={`Disconnect ${item.app.name}`}
                                description="Services stop receiving pushes until GitHub is connected again."
                            >
                                <ConfirmAction
                                    trigger="Disconnect"
                                    title={`Disconnect ${item.app.name}?`}
                                    description="launchroom deletes the app’s credentials, installations and repository list. Services that deploy from these repositories stop receiving pushes and can’t build until GitHub is connected again. The app itself stays on GitHub, where you can delete it."
                                    confirmLabel="Disconnect GitHub App"
                                    form={destroy.form(item.app.id)}
                                    destructive
                                />
                            </SettingsRow>
                        ) : (
                            <SettingsRow
                                key={item.connection.id}
                                label={`${item.connection.status === 'pending' ? 'Remove' : 'Disconnect'} ${item.connection.name}`}
                                description={
                                    item.connection.status === 'pending'
                                        ? 'It was never finished; nothing uses it yet.'
                                        : 'Services from it stop receiving pushes and can’t build until it is connected again.'
                                }
                            >
                                <ConfirmAction
                                    trigger={
                                        item.connection.status === 'pending'
                                            ? 'Remove'
                                            : 'Disconnect'
                                    }
                                    title={`Disconnect ${item.connection.name}?`}
                                    description={
                                        item.connection.provider === 'git'
                                            ? 'launchroom deletes the deploy key and the pinned host key. Remove the deploy key from the git server too.'
                                            : `launchroom deletes its token and repository list and removes the webhooks it added on ${item.connection.host}. Revoke the application there if you no longer need it.`
                                    }
                                    confirmLabel="Disconnect"
                                    form={destroyConnection.form(
                                        item.connection.id,
                                    )}
                                    destructive
                                />
                            </SettingsRow>
                        ),
                    )}
                </SettingsCard>
            )}
        </>
    );
}

GitSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        {
            title: 'Git',
            href: edit(),
        },
    ],
};
