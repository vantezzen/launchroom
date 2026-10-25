import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    GitBranch,
    GitFork,
    GitPullRequest,
    Lock,
} from 'lucide-react';
import { useState } from 'react';
import { ObjectCard, UrlCard } from '@/components/cards';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { compactUntil, plural } from '@/features/canvas/format';
import { ShareLinksDialog } from '@/features/previews/share-links';
import { previewState } from '@/features/previews/status';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import type { ProjectShellProps } from '@/features/projects/types';
import { canvas, settings } from '@/routes/projects';
import { approve } from '@/routes/projects/previews';

type Preview = App.Data.Previews.PreviewData;

type Props = ProjectShellProps & {
    previews: Preview[];
    enabled: boolean;
    canShare: boolean;
    canApprove: boolean;
    canManage: boolean;
};

/** One quiet line per database: how this preview got its data. */
function databaseLine(database: Preview['databases'][number]): {
    text: string;
    danger: boolean;
} {
    if (database.downgradedFrom) {
        return {
            text: `${database.service}: new empty (${database.downgradeReason === 'production' ? 'production data is not copied into previews' : 'forks don’t get base data'})`,
            danger: false,
        };
    }

    switch (database.strategy) {
        case 'share':
            return {
                text: `${database.service}: shared with ${database.source ?? 'the base'}`,
                danger: true,
            };
        case 'clone':
            return {
                text: `${database.service}: cloned from ${database.source ?? 'a backup'}${database.state && database.state !== 'ready' ? ` (${database.state})` : ''}${database.message && database.state === 'failed' ? `: ${database.message}` : ''}`,
                danger: database.state === 'failed',
            };
        default:
            return {
                text: `${database.service}: new empty database`,
                danger: false,
            };
    }
}

function PreviewTile({
    project,
    preview,
    canShare,
}: {
    project: string;
    preview: Preview;
    canShare: boolean;
}) {
    const [sharing, setSharing] = useState(false);
    const open = preview.status !== 'closed' && preview.status !== 'expired';
    const { state, label } = previewState(preview.status, preview.health);
    const url = preview.urls[0]?.url ?? null;

    return (
        <>
            <ObjectCard
                title={
                    <>
                        <span className="mr-1.5 font-normal text-muted-foreground tabular-nums">
                            #{preview.number}
                        </span>
                        {preview.title || 'Untitled'}
                    </>
                }
                href={
                    preview.environmentId && open
                        ? canvas([project, preview.environmentId])
                        : undefined
                }
                media={
                    url ? (
                        <UrlCard url={url.replace(/^https:\/\//, '')} />
                    ) : (
                        <GitPullRequest
                            aria-hidden="true"
                            strokeWidth={1.25}
                            className="size-10 text-faint"
                        />
                    )
                }
                status={
                    <Status
                        state={state}
                        label={label}
                        className="ml-auto shrink-0 type-small"
                    />
                }
                footer={
                    <>
                        <span className="inline-flex min-w-0 items-center gap-1.5">
                            <GitBranch
                                aria-hidden="true"
                                className="size-3.5 shrink-0"
                            />
                            <span className="truncate font-mono">
                                {preview.headRef}
                            </span>
                            <span aria-hidden="true">→</span>
                            <span className="sr-only">into</span>
                            <span className="font-mono">{preview.baseRef}</span>
                        </span>
                        <span className="inline-flex shrink-0 items-center gap-1.5">
                            {preview.protected && (
                                <Lock
                                    aria-label="Protected"
                                    className="size-3.5"
                                />
                            )}
                            {preview.expiresAt && open
                                ? `Expires in ${compactUntil(preview.expiresAt)}`
                                : null}
                        </span>
                    </>
                }
            >
                <div className="-mt-2 flex flex-col gap-1.5 type-small text-muted-foreground">
                    <p className="flex items-center gap-1.5">
                        {preview.author}
                        {preview.isFork && (
                            <span className="inline-flex items-center gap-1">
                                <GitFork
                                    aria-hidden="true"
                                    className="size-3"
                                />
                                fork
                            </span>
                        )}
                        <span className="type-mono-small">
                            {preview.headSha}
                        </span>
                    </p>
                    {preview.statusReason && preview.status !== 'running' && (
                        <p>{preview.statusReason}</p>
                    )}
                    {preview.databases.map((database) => {
                        const line = databaseLine(database);

                        return (
                            <p
                                key={database.service}
                                className={
                                    line.danger
                                        ? 'text-status-error'
                                        : undefined
                                }
                            >
                                {line.text}
                            </p>
                        );
                    })}
                    {preview.secretsWithheld && (
                        <p>Runs without secrets (fork).</p>
                    )}
                    {preview.protected && preview.environmentId && open && (
                        <button
                            type="button"
                            onClick={() => setSharing(true)}
                            className="relative z-10 self-start rounded-sm text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Share a link
                        </button>
                    )}
                </div>
            </ObjectCard>
            {sharing && (
                <ShareLinksDialog
                    project={project}
                    preview={preview}
                    open={sharing}
                    onOpenChange={setSharing}
                    canShare={canShare}
                />
            )}
        </>
    );
}

/**
 * A fork pull request waiting for an approval (flows §13): the commit to
 * approve, the safety sentence and one primary action.
 */
function ApprovalRow({
    project,
    preview,
    canApprove,
}: {
    project: string;
    preview: Preview;
    canApprove: boolean;
}) {
    const [busy, setBusy] = useState(false);

    return (
        <li className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <GitFork
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                />
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="truncate type-label">
                        <span className="font-normal text-muted-foreground">
                            #{preview.number}
                        </span>{' '}
                        {preview.title || 'Untitled'}
                    </p>
                    <MetaLine className="type-small text-muted-foreground">
                        {preview.author}
                        <span>
                            Commit{' '}
                            <span className="type-mono-small">
                                {preview.headSha}
                            </span>
                        </span>
                        {preview.pullRequestUrl && (
                            <a
                                href={`${preview.pullRequestUrl}/files`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-0.5 text-foreground underline underline-offset-4"
                            >
                                View changes on GitHub
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3"
                                />
                            </a>
                        )}
                    </MetaLine>
                </div>
            </div>
            {canApprove ? (
                <Button
                    size="sm"
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        router.post(
                            approve([project, preview.id]).url,
                            // The approval covers exactly this commit.
                            { headSha: preview.headCommit },
                            {
                                preserveScroll: true,
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    Approve this commit
                </Button>
            ) : (
                <span className="type-small text-muted-foreground">
                    An admin approves fork previews.
                </span>
            )}
        </li>
    );
}

/**
 * Pull request previews (direction §9.11, flows §13): fork approvals first
 * ("Waiting for you"), then one card per pull request.
 */
export default function ProjectPreviews({
    project,
    previews,
    enabled,
    canShare,
    canApprove,
    canManage,
}: Props) {
    const environments = previews
        .map((preview) => preview.environmentId)
        .filter((id): id is string => id !== null);
    const waiting = previews.filter(
        (preview) => preview.status === 'awaiting_approval',
    );
    const rest = previews.filter(
        (preview) => preview.status !== 'awaiting_approval',
    );
    const live = rest.filter(
        (preview) =>
            previewState(preview.status, preview.health).state === 'active',
    ).length;

    return (
        <>
            <Head title={`Previews - ${project.name}`} />
            <EnvironmentLiveUpdates
                environments={environments}
                only={['previews']}
            />
            <Page>
                <PageHeader
                    title="Previews"
                    description={
                        !enabled ? (
                            'Off for this project.'
                        ) : previews.length === 0 ? (
                            'Each pull request gets its own environment.'
                        ) : (
                            <MetaLine>
                                {plural(previews.length, 'pull request')}
                                {`${live} live`}
                                {waiting.length > 0 &&
                                    `${waiting.length} waiting for you`}
                            </MetaLine>
                        )
                    }
                    actions={
                        canManage &&
                        previews.length > 0 && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={settings({
                                        project: project.id,
                                        section: 'previews',
                                    })}
                                >
                                    Preview settings
                                </Link>
                            </Button>
                        )
                    }
                />

                {waiting.length > 0 && (
                    <Section
                        title="Waiting for you"
                        description="Fork previews run without secrets, data or access to your network."
                    >
                        <ul className="divide-y overflow-hidden rounded-[14px] border bg-card shadow-card">
                            {waiting.map((preview) => (
                                <ApprovalRow
                                    key={preview.id}
                                    project={project.id}
                                    preview={preview}
                                    canApprove={canApprove}
                                />
                            ))}
                        </ul>
                    </Section>
                )}

                {rest.length > 0 ? (
                    <Section
                        title={waiting.length > 0 ? 'Pull requests' : undefined}
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            {rest.map((preview) => (
                                <PreviewTile
                                    key={preview.id}
                                    project={project.id}
                                    preview={preview}
                                    canShare={canShare}
                                />
                            ))}
                        </div>
                    </Section>
                ) : (
                    waiting.length === 0 && (
                        <EmptyState
                            icon={GitPullRequest}
                            action={
                                canManage && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={settings({
                                                project: project.id,
                                                section: 'previews',
                                            })}
                                        >
                                            Preview settings
                                        </Link>
                                    </Button>
                                )
                            }
                        >
                            {enabled
                                ? 'Open a pull request to get a preview.'
                                : 'Previews are off. When they’re on, every pull request gets its own environment.'}
                        </EmptyState>
                    )
                )}
            </Page>
        </>
    );
}
