import { useForm } from '@inertiajs/react';
import { ArrowUpRight, ShieldCheck, TriangleAlert } from 'lucide-react';
import { useEffect, useEffectEvent, useId } from 'react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Callout } from '@/components/patterns/callout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { listNames } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as backupsIndex } from '@/routes/backups';
import update from '@/routes/projects/services/update';
import { kindLabel, publishedWords, targetName } from './format';
import type { ServiceUpdate, UpdateCandidate } from './format';
import { VersionPair } from './version-pair';

/**
 * Update a service to a newer release (docs/04 §14.4): the two versions,
 * a choice when there is more than one, for a major the release notes
 * first, then what happens as three numbered steps and one button.
 */
export function UpdateDialog({
    service,
    open,
    onOpenChange,
    initialTag,
    onStarted,
}: {
    service: ServiceUpdate;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** After the update started (pages that load their data themselves refresh it). */
    onStarted?: () => void;
    /** The candidate to preselect; the recommended one otherwise. */
    initialTag?: string;
}) {
    const fallback = initialTag ?? service.recommended?.tag ?? '';
    const form = useForm({ tag: fallback, releaseNotesRead: false });
    const candidate =
        service.candidates.find((c) => c.tag === form.data.tag) ??
        service.recommended;
    const major = candidate?.kind === 'major';

    const reseed = useEffectEvent(() => {
        form.setDefaults({ tag: fallback, releaseNotesRead: false });
        form.reset();
        form.clearErrors();
    });

    useEffect(() => {
        if (open) {
            reseed();
        }
    }, [open]);

    if (!candidate) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            update.store({
                project: service.projectId,
                environment: service.environmentId,
                service: service.serviceId,
            }).url,
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onStarted?.();
                },
            },
        );
    };

    const blocked = service.blocker !== null;
    const disabled =
        form.processing || blocked || (major && !form.data.releaseNotesRead);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100dvh-2rem)] gap-6 overflow-y-auto sm:max-w-xl">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <div className="flex flex-col gap-1.5">
                        <DialogTitle>
                            Update {service.serviceName} to{' '}
                            {targetName(candidate)}
                        </DialogTitle>
                        <DialogDescription>
                            {kindLabel(candidate.kind)} in{' '}
                            {service.environmentName}.
                            {candidate.curatedBy &&
                                ` Tested in launchroom’s ${candidate.curatedBy} template.`}
                        </DialogDescription>
                    </div>

                    <VersionPair
                        from={service.running ?? 'Running version'}
                        fromPublishedAt={service.runningPublishedAt}
                        to={candidate.version}
                        toPublishedAt={candidate.publishedAt}
                    />

                    {service.candidates.length > 1 && (
                        <CandidateChoice
                            candidates={service.candidates}
                            value={form.data.tag}
                            onChange={(tag) => {
                                form.setData({ tag, releaseNotesRead: false });
                            }}
                        />
                    )}

                    {major && (
                        <Callout
                            tone="warning"
                            title="Read the release notes first"
                            actions={
                                service.releaseNotesUrl ? (
                                    <a
                                        href={service.releaseNotesUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-0.5 text-signal hover:underline"
                                    >
                                        Release notes
                                        <ArrowUpRight
                                            aria-hidden="true"
                                            className="size-3"
                                        />
                                    </a>
                                ) : undefined
                            }
                        >
                            A major update can change settings, remove features
                            or migrate data in a way the old version can’t read.
                            <label className="mt-3 flex items-center gap-2 text-foreground">
                                <Checkbox
                                    checked={form.data.releaseNotesRead}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'releaseNotesRead',
                                            checked === true,
                                        )
                                    }
                                />
                                I read the release notes and want to update
                            </label>
                            <InputError
                                message={form.errors.releaseNotesRead}
                            />
                        </Callout>
                    )}

                    <Steps service={service} candidate={candidate} />

                    {blocked && (
                        <Callout tone="warning" title="Not right now">
                            {service.blocker}
                        </Callout>
                    )}
                    <InputError
                        message={
                            form.errors.tag ??
                            (form.errors as Record<string, string | undefined>)
                                .backup ??
                            (form.errors as Record<string, string | undefined>)
                                .general
                        }
                    />

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        {service.releaseNotesUrl && !major ? (
                            <a
                                href={service.releaseNotesUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-0.5 type-small text-muted-foreground hover:text-foreground hover:underline"
                            >
                                Release notes
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3"
                                />
                            </a>
                        ) : (
                            <span />
                        )}
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={disabled}>
                                Update to {targetName(candidate)}
                            </Button>
                        </div>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** One row per available version, as a radio group. */
function CandidateChoice({
    candidates,
    value,
    onChange,
}: {
    candidates: UpdateCandidate[];
    value: string;
    onChange: (tag: string) => void;
}) {
    const name = useId();

    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 type-label">Version</legend>
            <div className="overflow-hidden rounded-xl border">
                {candidates.map((candidate) => {
                    const selected = candidate.tag === value;
                    const published = publishedWords(candidate.publishedAt);

                    return (
                        <label
                            key={candidate.tag}
                            className={cn(
                                'flex cursor-pointer items-center gap-3 border-t px-4 py-3 first:border-t-0',
                                'has-[:focus-visible]:bg-hover',
                                selected ? 'bg-hover' : 'hover:bg-hover',
                            )}
                        >
                            <input
                                type="radio"
                                name={name}
                                value={candidate.tag}
                                checked={selected}
                                onChange={() => onChange(candidate.tag)}
                                className="size-4 accent-foreground"
                            />
                            <span className="min-w-0 flex-1 truncate type-mono">
                                {candidate.version}
                            </span>
                            <span className="flex shrink-0 items-center gap-1 type-small text-muted-foreground">
                                {candidate.kind === 'major' && (
                                    <TriangleAlert
                                        aria-hidden="true"
                                        className="size-3.5 text-status-progress"
                                    />
                                )}
                                {kindLabel(candidate.kind)}
                            </span>
                            {published && (
                                <span className="hidden shrink-0 type-small text-muted-foreground sm:inline">
                                    {published}
                                </span>
                            )}
                        </label>
                    );
                })}
            </div>
        </fieldset>
    );
}

/** What happens, in order, with this service's specifics. */
function Steps({
    service,
    candidate,
}: {
    service: ServiceUpdate;
    candidate: UpdateCandidate;
}) {
    const backup: ReactNode =
        service.backups.length > 0 ? (
            `${capitalise(listNames(service.backups))} ${service.backups.length === 1 ? 'goes' : 'go'} to your backup storage first.`
        ) : !service.keepsData ? (
            `${service.serviceName} keeps no data of its own, so there is nothing to back up.`
        ) : (
            <>
                No backup storage yet, so there is no backup from before the
                update.{' '}
                <a
                    href={backupsIndex().url}
                    className="text-signal hover:underline"
                >
                    Set up backups
                </a>
            </>
        );

    const steps: { title: string; detail: ReactNode }[] = [
        { title: 'Back up', detail: backup },
        {
            title: `Deploy ${candidate.version}`,
            detail: `${service.serviceName} restarts with the new image in ${service.environmentName}. Other environments get it staged for their next deploy.`,
        },
        {
            title: `Watch for ${service.watchMinutes} minutes`,
            detail: `If it keeps crashing, launchroom rolls back to ${service.running ?? 'the running version'}.`,
        },
    ];

    return (
        <ol className="flex flex-col gap-3" aria-label="What happens">
            {steps.map((step, index) => (
                <li key={step.title} className="flex gap-3">
                    <span
                        aria-hidden="true"
                        className="flex size-6 shrink-0 items-center justify-center rounded-full border bg-card type-small font-medium tabular-nums"
                    >
                        {index + 1}
                    </span>
                    <div className="flex min-w-0 flex-col gap-0.5 pt-0.5">
                        <span className="type-label">{step.title}</span>
                        <span className="type-small text-muted-foreground">
                            {step.detail}
                        </span>
                    </div>
                </li>
            ))}
            {service.backups.length > 0 && (
                <li className="flex items-center gap-2 pl-9 type-small text-muted-foreground">
                    <ShieldCheck aria-hidden="true" className="size-3.5" />
                    The backups stay in your backup storage, ready to restore.
                </li>
            )}
        </ol>
    );
}

function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}
