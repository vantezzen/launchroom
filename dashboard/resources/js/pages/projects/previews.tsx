import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    GitBranch,
    GitFork,
    GitPullRequest,
    Lock,
} from 'lucide-react';
import { useState } from 'react';
import { ObjectCard } from '@/components/cards/object-card';
import { UrlCard } from '@/features/previews/url-card';
import { EmptyState } from '@/components/patterns/empty-state';
import { MetaLine } from '@/components/patterns/meta-line';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Status } from '@/components/status';
import { Button } from '@/components/ui/button';
import { ShareLinksDialog } from '@/features/previews/share-links';
import { previewState } from '@/features/previews/status';
import { EnvironmentLiveUpdates } from '@/features/projects/environment-live-updates';
import { canvas, settings } from '@/routes/projects';
import { approve } from '@/routes/projects/previews';
import { show as deploymentShow } from '@/routes/deployments';
import { TimeAgo } from '@/components/time-ago';
import { compactUntil, plural } from '@/lib/format';
import ProjectLayout from '@/features/app-shell/project-layout';

type Preview = App.Data.Previews.PreviewData;

type Props = App.Data.Canvas.ProjectShellPropsData & {
    previews: Preview[];
    rule: App.Data.Previews.PreviewRuleData;
    enabled: boolean;
    canShare: boolean;
    canApprove: boolean;
    canManage: boolean;
    shareLinkDays: App.Data.Forms.NumberRangeData;
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
    shareLinkDays,
}: {
    project: string;
    preview: Preview;
    canShare: boolean;
    shareLinkDays: App.Data.Forms.NumberRangeData;
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
                    ) : undefined
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
                    <PreviewActions project={project} preview={preview} />
                </div>
            </ObjectCard>
            {sharing && (
                <ShareLinksDialog
                    project={project}
                    preview={preview}
                    open={sharing}
                    onOpenChange={setSharing}
                    canShare={canShare}
                    daysRange={shareLinkDays}
                />
            )}
        </>
    );
}

/**
 * Links out of a preview card (DPL-6): the preview itself, its pull
 * request and, when a deployment failed, the explanation.
 */
function PreviewActions({
    project,
    preview,
}: {
    project: string;
    preview: Preview;
}) {
    const url = preview.urls[0]?.url ?? null;
    const why =
        preview.health === 'failed' &&
        preview.failedDeploymentId &&
        preview.environmentId
            ? deploymentShow({
                  project,
                  environment: preview.environmentId,
                  deployment: preview.failedDeploymentId,
              }).url
            : null;

    if (!url && !preview.pullRequestUrl && !why) {
        return null;
    }

    return (
        <div className="relative z-10 flex flex-wrap gap-2 pt-1.5">
            {why && (
                <Button size="sm" asChild>
                    <Link href={why}>See why</Link>
                </Button>
            )}
            {url && (
                <Button size="sm" variant="outline" asChild>
                    <a href={url} target="_blank" rel="noreferrer">
                        Open preview
                        <ArrowUpRight aria-hidden="true" />
                    </a>
                </Button>
            )}
            {preview.pullRequestUrl && (
                <Button size="sm" variant="ghost" asChild>
                    <a
                        href={preview.pullRequestUrl}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Pull request
                        <ArrowUpRight aria-hidden="true" />
                    </a>
                </Button>
            )}
        </div>
    );
}

/** Closed and expired pull requests: one line each (DPL-6). */
function ClosedRow({ preview }: { preview: Preview }) {
    const { state, label } = previewState(preview.status, preview.health);

    return (
        <li className="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-center sm:gap-4">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="truncate type-label">
                    <span className="font-normal text-muted-foreground tabular-nums">
                        #{preview.number}
                    </span>{' '}
                    {preview.pullRequestUrl ? (
                        <a
                            href={preview.pullRequestUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="underline-offset-4 hover:underline"
                        >
                            {preview.title || 'Untitled'}
                        </a>
                    ) : (
                        preview.title || 'Untitled'
                    )}
                </p>
                <MetaLine className="type-small text-muted-foreground">
                    {preview.author}
                    <span className="inline-flex min-w-0 items-center gap-1">
                        <span className="truncate font-mono">
                            {preview.headRef}
                        </span>
                        <span aria-hidden="true">→</span>
                        <span className="sr-only">into</span>
                        <span className="font-mono">{preview.baseRef}</span>
                    </span>
                    {preview.statusReason ?? (
                        <span>
                            Last activity{' '}
                            <TimeAgo value={preview.lastActivityAt} />
                        </span>
                    )}
                </MetaLine>
            </div>
            <span className="flex shrink-0 items-center gap-3 type-small">
                <Status state={state} label={label} />
            </span>
        </li>
    );
}

/** "Pull requests into main get a copy of staging. Removed when …" */
function ruleSentence(rule: App.Data.Previews.PreviewRuleData): string {
    const every = rule.branches.length === 0;
    const into = every
        ? 'Every pull request'
        : `Pull requests into ${listWords(rule.branches)}`;
    const label = rule.requiredLabel
        ? ` with the ${rule.requiredLabel} label`
        : '';
    const verb = every ? 'gets' : 'get';
    const copy = rule.baseEnvironment
        ? `${verb} a copy of ${rule.baseEnvironment}`
        : `${verb} ${every ? 'its' : 'their'} own environment`;
    const removed =
        rule.expiryDays > 0
            ? `Removed when closed or after ${plural(rule.expiryDays, 'day')} without activity.`
            : 'Removed when closed.';

    return `${into}${label} ${copy}. ${removed}`;
}

function listWords(items: string[]): string {
    return items.length <= 1
        ? (items[0] ?? '')
        : `${items.slice(0, -1).join(', ')} or ${items.at(-1)}`;
}

/** Closed rows shown before "Show all". */
const CLOSED_SHOWN = 5;

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
    rule,
    enabled,
    canShare,
    canApprove,
    canManage,
    shareLinkDays,
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
    const isClosed = (preview: Preview) =>
        preview.status === 'closed' || preview.status === 'expired';
    const open = rest.filter((preview) => !isClosed(preview));
    const closed = rest.filter(isClosed);
    const [allClosed, setAllClosed] = useState(false);

    return (
        <>
            <Head title={`Previews - ${project.name}`} />
            <EnvironmentLiveUpdates
                environments={environments}
                only={['previews']}
                statuses
            />
            <Page>
                <PageHeader
                    title="Previews"
                    description={
                        !enabled ? 'Off for this project.' : ruleSentence(rule)
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

                {open.length > 0 && (
                    <Section
                        title={
                            <SectionCount title="Open" count={open.length} />
                        }
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            {open.map((preview) => (
                                <PreviewTile
                                    key={preview.id}
                                    project={project.id}
                                    preview={preview}
                                    canShare={canShare}
                                    shareLinkDays={shareLinkDays}
                                />
                            ))}
                        </div>
                    </Section>
                )}

                {closed.length > 0 && (
                    <Section
                        title={
                            <SectionCount
                                title="Closed"
                                count={closed.length}
                            />
                        }
                    >
                        <ul className="divide-y overflow-hidden rounded-[14px] border bg-card shadow-card">
                            {(allClosed
                                ? closed
                                : closed.slice(0, CLOSED_SHOWN)
                            ).map((preview) => (
                                <ClosedRow key={preview.id} preview={preview} />
                            ))}
                        </ul>
                        {closed.length > CLOSED_SHOWN && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="self-start"
                                onClick={() => setAllClosed(!allClosed)}
                            >
                                {allClosed
                                    ? 'Show fewer'
                                    : `Show all ${closed.length}`}
                            </Button>
                        )}
                    </Section>
                )}

                {rest.length === 0 && waiting.length === 0 && (
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
                )}
            </Page>
        </>
    );
}

ProjectPreviews.layout = ProjectLayout;

function SectionCount({ title, count }: { title: string; count: number }) {
    return (
        <>
            {title}{' '}
            <span className="font-normal text-muted-foreground tabular-nums">
                {count}
            </span>
        </>
    );
}
