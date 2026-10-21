import { Head, Link, router } from '@inertiajs/react';
import { Page } from '@/components/patterns/page';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TimeAgo } from '@/components/time-ago';
import type { ProjectShellProps } from '@/features/projects/types';
import { StageOption } from '@/features/staged-changes/deploy-dialog';
import {
    Blockers,
    ChangeRow,
} from '@/features/staged-changes/staged-changes-sheet';
import {
    effectLabel,
    effectSummary,
} from '@/features/staged-changes/use-staged-changes';
import { canvas } from '@/routes/projects';
import { revert } from '@/routes/environments';
import { deploy, discard } from '@/routes/staged-changes';

type Props = ProjectShellProps & {
    changes: App.Data.StagedChanges.StagedChangesData;
    revisions: App.Data.StagedChanges.RevisionData[];
};

function Revisions({
    project,
    environment,
    revisions,
    canRevert,
}: {
    project: string;
    environment: string;
    revisions: App.Data.StagedChanges.RevisionData[];
    canRevert: boolean;
}) {
    const [target, setTarget] =
        useState<App.Data.StagedChanges.RevisionData | null>(null);

    if (revisions.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby="revisions-title"
            className="flex flex-col gap-2"
        >
            <div className="flex flex-col gap-1 border-b pb-2">
                <h2 id="revisions-title" className="type-heading">
                    History
                </h2>
                <p className="type-small text-muted-foreground">
                    Reverting stages an older configuration for you to review
                    and deploy.
                </p>
            </div>
            <ul className="flex flex-col">
                {revisions.map((revision) => (
                    <li
                        key={revision.id}
                        className="flex items-center gap-3 border-b py-2 last:border-0"
                    >
                        <span className="w-24 shrink-0 type-label tabular-nums">
                            Revision {revision.number}
                        </span>
                        <span className="min-w-0 flex-1 truncate type-body text-muted-foreground">
                            {revision.message ?? 'No message'}
                            {revision.author && ` · ${revision.author}`}
                        </span>
                        <span className="type-small text-muted-foreground">
                            <TimeAgo value={revision.createdAt} />
                        </span>
                        {revision.current ? (
                            <span className="w-28 text-right type-small text-muted-foreground">
                                Live
                            </span>
                        ) : (
                            canRevert && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 w-28"
                                    onClick={() => setTarget(revision)}
                                >
                                    Revert to this
                                </Button>
                            )
                        )}
                    </li>
                ))}
            </ul>
            <Dialog
                open={target !== null}
                onOpenChange={(open) => !open && setTarget(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Revert to revision {target?.number}?
                    </DialogTitle>
                    <DialogDescription>
                        Services, settings and variables go back to how revision{' '}
                        {target?.number} had them, as staged changes. Edits
                        staged now are replaced. Nothing goes live until you
                        deploy.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button
                            onClick={() => {
                                if (target) {
                                    router.post(
                                        revert([
                                            project,
                                            environment,
                                            target.id,
                                        ]).url,
                                    );
                                }

                                setTarget(null);
                            }}
                        >
                            Revert to revision {target?.number}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

export default function StagedChangesPage({
    changes,
    project,
    revisions,
}: Props) {
    const [message, setMessage] = useState('');
    const [stage, setStage] = useState(false);
    const canStage =
        changes.environmentKind === 'production' && !changes.neverDeployed;
    const [processing, setProcessing] = useState(false);
    const [confirmAll, setConfirmAll] = useState(false);
    const canDiscard = changes.canDeploy && !changes.neverDeployed;
    const effects = effectSummary(changes);
    const nothing = changes.count === 0 && !changes.neverDeployed;

    const discardPaths = (paths: string[] | null) =>
        router.post(
            discard({ project: project.id, environment: changes.environmentId })
                .url,
            paths === null ? {} : { paths },
            { preserveScroll: true },
        );

    const submit = () => {
        setProcessing(true);
        router.post(
            deploy({ project: project.id, environment: changes.environmentId })
                .url,
            {
                message: message.trim() === '' ? null : message.trim(),
                stage: canStage && stage,
            },
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <>
            <Head title={`Staged changes · ${changes.environmentName}`} />
            <Page className="gap-8">
                <header className="flex flex-col gap-2">
                    <h1 className="type-title">Staged changes</h1>
                    <p className="type-body text-muted-foreground">
                        {nothing
                            ? `Nothing is staged in ${changes.environmentName}.`
                            : changes.neverDeployed
                              ? `${changes.environmentName} has never been deployed. Deploying starts every service in it.`
                              : `${changes.count} ${changes.count === 1 ? 'change' : 'changes'} in ${changes.environmentName} since revision ${changes.revision}.`}
                        {effects !== '' && !nothing && (
                            <span className="type-mono-small"> {effects}</span>
                        )}
                    </p>
                </header>

                {changes.blockers.length > 0 && (
                    <Blockers blockers={changes.blockers} />
                )}

                {changes.promotedImages.length > 0 && (
                    <section
                        aria-labelledby="promoted-images-title"
                        className="flex flex-col gap-2"
                    >
                        <h2
                            id="promoted-images-title"
                            className="border-b pb-2 type-heading"
                        >
                            Promoted images
                        </h2>
                        <ul className="flex flex-col gap-1">
                            {changes.promotedImages.map((image) => (
                                <li key={image.serviceId} className="type-body">
                                    {image.serviceName} deploys{' '}
                                    <span className="type-mono">
                                        {image.commitSha.slice(0, 7)}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        (running in {image.fromEnvironment})
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {nothing ? (
                    <section className="flex flex-col items-start gap-3">
                        <p className="max-w-[65ch] type-body text-muted-foreground">
                            Edits to services, variables, domains and settings
                            collect here until you deploy them, so several
                            changes go live together.
                        </p>
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={
                                    canvas([project.id, changes.environmentId])
                                        .url
                                }
                            >
                                Open the canvas
                            </Link>
                        </Button>
                    </section>
                ) : (
                    <div className="grid gap-8 lg:grid-cols-[1fr_320px]">
                        <div className="flex flex-col gap-6">
                            {changes.groups.map((group) => (
                                <section
                                    key={group.serviceId ?? 'environment'}
                                    className="flex flex-col gap-3 rounded-[14px] border bg-card p-4 shadow-card"
                                >
                                    <div className="flex items-center gap-2">
                                        <h2 className="type-label">
                                            {group.name}
                                        </h2>
                                        {group.serviceId &&
                                            group.effect !== 'none' && (
                                                <span className="inline-flex h-5 items-center rounded-full bg-hover px-2 type-small text-muted-foreground">
                                                    {effectLabel(group.effect)}
                                                </span>
                                            )}
                                        {canDiscard &&
                                            group.serviceId &&
                                            group.items.length > 1 && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="ml-auto h-7"
                                                    onClick={() =>
                                                        discardPaths(
                                                            group.items.map(
                                                                (item) =>
                                                                    item.path,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Discard all
                                                </Button>
                                            )}
                                    </div>
                                    {group.items.length === 0 ? (
                                        <p className="type-small text-muted-foreground">
                                            Restarts because a variable it
                                            references changed.
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col gap-1">
                                            {group.items.map((item) => (
                                                <ChangeRow
                                                    key={item.path}
                                                    item={item}
                                                    prefix={
                                                        group.slug
                                                            ? `${group.slug}: `
                                                            : null
                                                    }
                                                    onDiscard={
                                                        canDiscard
                                                            ? () =>
                                                                  discardPaths([
                                                                      item.path,
                                                                  ])
                                                            : undefined
                                                    }
                                                />
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            ))}
                        </div>

                        {changes.canDeploy && (
                            <aside className="flex flex-col gap-4 self-start lg:sticky lg:top-0">
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="message">
                                        Message (optional)
                                    </Label>
                                    <Input
                                        id="message"
                                        value={message}
                                        maxLength={500}
                                        onChange={(event) =>
                                            setMessage(event.target.value)
                                        }
                                        placeholder="Add Redis for queues"
                                    />
                                </div>
                                {canStage && (
                                    <StageOption
                                        checked={stage}
                                        onChange={setStage}
                                    />
                                )}
                                <Button
                                    onClick={submit}
                                    disabled={
                                        processing ||
                                        changes.blockers.length > 0
                                    }
                                >
                                    {processing ? 'Deploying…' : 'Deploy'}
                                </Button>
                                {canDiscard && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setConfirmAll(true)}
                                    >
                                        Discard all
                                    </Button>
                                )}
                            </aside>
                        )}
                    </div>
                )}

                <Revisions
                    project={project.id}
                    environment={changes.environmentId}
                    revisions={revisions}
                    canRevert={changes.canDeploy}
                />
            </Page>

            <Dialog open={confirmAll} onOpenChange={setConfirmAll}>
                <DialogContent>
                    <DialogTitle>
                        Discard all {changes.count} changes?
                    </DialogTitle>
                    <DialogDescription>
                        Every staged edit in {changes.environmentName} goes back
                        to how revision {changes.revision} left it. New secrets
                        you entered are lost.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Keep changes</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setConfirmAll(false);
                                discardPaths(null);
                            }}
                        >
                            Discard all
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
