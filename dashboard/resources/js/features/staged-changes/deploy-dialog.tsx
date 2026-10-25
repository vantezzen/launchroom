import { Link, router } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import { requestJson } from '@/features/projects/http';
import { deploy, show as review, summary } from '@/routes/staged-changes';
import type { StagedChanges } from './use-staged-changes';
import { effectSummary } from './use-staged-changes';

/**
 * Deploy the staged changes with an optional message (docs/06 §4). Lands on
 * the project's deployments with live progress.
 */
export function DeployDialog({
    project,
    environment,
    changes,
    open,
    onOpenChange,
}: {
    project: string;
    environment: string;
    changes: StagedChanges;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [message, setMessage] = useState('');
    const [stage, setStage] = useState(false);
    const canStage =
        changes.environmentKind === 'production' && !changes.neverDeployed;
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const effects = effectSummary(changes);
    // Broken references refuse a deploy; check before submitting (the same
    // resolution the review page shows).
    const check = useQuery({
        queryKey: ['staged-changes', environment, 'blockers'],
        enabled: open,
        staleTime: 0,
        queryFn: ({ signal }) =>
            requestJson<StagedChanges>(
                'get',
                summary({ project, environment }, { query: { blockers: 1 } })
                    .url,
                undefined,
                signal,
            ),
    });
    const blockers = check.data?.blockers ?? [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>
                    Deploy{' '}
                    {changes.neverDeployed
                        ? changes.environmentName
                        : `${changes.count} ${changes.count === 1 ? 'change' : 'changes'}`}
                </DialogTitle>
                <DialogDescription>
                    {changes.neverDeployed
                        ? `The first deploy of ${changes.environmentName} starts every service in it.`
                        : effects !== ''
                          ? effects
                          : 'These changes apply without restarting a service.'}
                </DialogDescription>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setProcessing(true);
                        setError(null);
                        router.post(
                            deploy({ project, environment }).url,
                            {
                                message:
                                    message.trim() === ''
                                        ? null
                                        : message.trim(),
                                stage: canStage && stage,
                            },
                            {
                                onError: (errors) =>
                                    setError(
                                        Object.values(errors)[0] ??
                                            'The deploy failed.',
                                    ),
                                onSuccess: () => onOpenChange(false),
                                onFinish: () => setProcessing(false),
                            },
                        );
                    }}
                >
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="deploy-message">
                            Message (optional)
                        </Label>
                        <Input
                            id="deploy-message"
                            value={message}
                            maxLength={500}
                            onChange={(event) => setMessage(event.target.value)}
                            placeholder="Add Redis for queues"
                        />
                        <p className="type-small text-muted-foreground">
                            Shown in the activity feed with the revision.
                        </p>
                    </div>
                    {canStage && (
                        <StageOption checked={stage} onChange={setStage} />
                    )}
                    {check.isFetching && !check.data && (
                        <p className="flex items-center gap-2 type-small text-muted-foreground">
                            <Spinner /> Checking references
                        </p>
                    )}
                    {blockers.length > 0 && (
                        <div role="alert" className="flex flex-col gap-2">
                            <p className="type-label text-status-error">
                                {blockers.length === 1
                                    ? 'One reference is broken. Fix it before deploying:'
                                    : `${blockers.length} references are broken. Fix them before deploying:`}
                            </p>
                            <ul className="flex flex-col gap-1">
                                {blockers.slice(0, 8).map((blocker) => (
                                    <li
                                        key={blocker}
                                        className="flex gap-2 type-small"
                                    >
                                        <X
                                            aria-hidden="true"
                                            className="mt-0.5 size-3.5 shrink-0 text-status-error"
                                        />
                                        <span>{blocker}</span>
                                    </li>
                                ))}
                            </ul>
                            {blockers.length > 8 && (
                                <p className="type-small text-muted-foreground">
                                    And {blockers.length - 8} more.
                                </p>
                            )}
                            <Link
                                href={review({ project, environment })}
                                className="type-small underline underline-offset-4"
                                onClick={() => onOpenChange(false)}
                            >
                                Review the changes
                            </Link>
                        </div>
                    )}
                    {error && (
                        <p role="alert" className="type-body text-status-error">
                            {error}
                        </p>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={processing || blockers.length > 0}
                        >
                            {processing
                                ? 'Deploying…'
                                : canStage && stage
                                  ? 'Deploy staged'
                                  : 'Deploy'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Staged production deployments (docs/06 §6.5): built and started with
 * production config, routed only when promoted.
 */
export function StageOption({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id="deploy-stage"
                className="mt-0.5"
                checked={checked}
                onCheckedChange={(next) => onChange(next === true)}
            />
            <div className="flex flex-col gap-0.5">
                <Label htmlFor="deploy-stage" className="type-body font-normal">
                    Stage without routing traffic
                </Label>
                <p className="type-small text-muted-foreground">
                    New versions are built and started next to the current ones.
                    Promote them from Deployments to switch traffic. Databases
                    deploy as usual.
                </p>
            </div>
        </div>
    );
}
