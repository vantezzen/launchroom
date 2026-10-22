import { router } from '@inertiajs/react';
import { ArrowUpRight, RefreshCw } from 'lucide-react';
import { Logo } from '@/components/logo';
import { TimeAgo } from '@/components/time-ago';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { GitHubSetupSteps } from '@/features/git/github-setup-steps';
import { WebhookHealth } from '@/features/git/webhook-health';
import { SettingsRow } from '@/features/settings/settings-card';
import { refresh } from '@/routes/git/installations';

function InstallationRow({
    installation,
    canManage,
}: {
    installation: App.Data.Git.GitHubInstallationData;
    canManage: boolean;
}) {
    const repos = `${installation.repositoryCount} ${installation.repositoryCount === 1 ? 'repository' : 'repositories'}`;

    return (
        <SettingsRow
            media={
                <Avatar className="size-9">
                    <AvatarFallback className="bg-hover type-label text-muted-foreground">
                        {installation.accountLogin.slice(0, 2).toUpperCase()}
                    </AvatarFallback>
                </Avatar>
            }
            label={installation.accountLogin}
            description={
                installation.suspended ? (
                    'Suspended on GitHub: pushes from here are ignored'
                ) : (
                    <>
                        {installation.repositorySelection === 'all'
                            ? `All ${repos}`
                            : `${repos} selected`}
                        {' · '}
                        {installation.accountType === 'Organization'
                            ? 'organization'
                            : 'personal account'}
                        {installation.syncedAt !== null && (
                            <>
                                {' · synced '}
                                <TimeAgo value={installation.syncedAt} />
                            </>
                        )}
                    </>
                )
            }
        >
            {canManage && !installation.suspended && (
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={`Refresh the repositories of ${installation.accountLogin}`}
                    title="Refresh repositories"
                    onClick={() =>
                        router.post(
                            refresh(installation.id).url,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <RefreshCw aria-hidden="true" />
                </Button>
            )}
            <Button variant="outline" size="sm" asChild>
                <a
                    href={installation.settingsUrl}
                    target="_blank"
                    rel="noreferrer noopener"
                >
                    Configure
                    <ArrowUpRight aria-hidden="true" />
                </a>
            </Button>
        </SettingsRow>
    );
}

/**
 * One GitHub App as a card (docs/design/flows.md §2): who owns it, one
 * health line, the accounts it is installed on.
 */
export function GitHubAppSection({ app }: { app: App.Data.Git.GitHubAppData }) {
    return (
        <section
            aria-labelledby={app.id}
            className="overflow-hidden rounded-xl border bg-card shadow-card"
        >
            <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-4">
                    <Logo kind="github" size={44} decorative />
                    <div className="flex min-w-0 flex-col gap-1">
                        <h3 id={app.id} className="truncate type-heading">
                            {app.name}
                        </h3>
                        <p className="type-small text-muted-foreground">
                            {app.ownerLogin !== null
                                ? `Owned by ${app.ownerLogin}`
                                : 'GitHub App'}
                            {app.isEnterprise && (
                                <>
                                    {' on '}
                                    <span className="type-mono-small">
                                        {app.webUrl}
                                    </span>
                                </>
                            )}
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" asChild>
                    <a
                        href={app.htmlUrl}
                        target="_blank"
                        rel="noreferrer noopener"
                    >
                        Manage on GitHub
                        <ArrowUpRight aria-hidden="true" />
                    </a>
                </Button>
            </div>
            <div className="border-t bg-recessed px-5 py-3">
                <WebhookHealth webhook={app.webhook} />
            </div>
            <div className="divide-y border-t">
                {app.installations.length === 0 ? (
                    <div className="p-4 sm:p-5">
                        <GitHubSetupSteps
                            appName={app.name}
                            installUrl={app.installUrl}
                            canManage={app.canManage}
                        />
                    </div>
                ) : (
                    app.installations.map((installation) => (
                        <InstallationRow
                            key={installation.id}
                            installation={installation}
                            canManage={app.canManage}
                        />
                    ))
                )}
            </div>
            {app.canManage && app.installations.length > 0 && (
                <div className="border-t px-5 py-3">
                    <Button variant="ghost" size="sm" asChild>
                        <a href={app.installUrl}>Install on another account</a>
                    </Button>
                </div>
            )}
        </section>
    );
}
