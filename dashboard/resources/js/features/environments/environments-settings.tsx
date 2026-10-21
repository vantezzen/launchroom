import { Form, Link, router } from '@inertiajs/react';
import { MoreHorizontal, Pause, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Section } from '@/components/patterns/section';
import { Badge } from '@/components/ui/badge';
import { TimeAgo } from '@/components/time-ago';
import { PendingDeletion } from '@/features/backups/pending-deletion';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { destroy, resume, update } from '@/routes/environments';
import { canvas } from '@/routes/projects';
import environmentRoutes from '@/routes/projects/environments';
import { show as promote } from '@/routes/promotions';

type Settings = App.Data.Environments.EnvironmentSettingsData;

const kindLabels: Record<App.Enums.EnvironmentKind, string> = {
    production: 'Production',
    persistent: 'Persistent',
    preview: 'Preview',
};

/**
 * "main", "tags v*", "release/* and tags v*" or "Manual only" (docs/06 §2).
 */
export function triggerSummary(
    triggers: App.Data.Environments.EnvironmentTriggers,
): string | null {
    const parts = [
        triggers.branch,
        triggers.tagPattern ? `tags ${triggers.tagPattern}` : null,
    ].filter((part): part is string => part !== null && part !== '');

    return parts.length === 0 ? null : parts.join(' and ');
}

function EditEnvironmentDialog({
    project,
    environment,
    open,
    onOpenChange,
}: {
    project: string;
    environment: Settings;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Edit {environment.name}</DialogTitle>
                <DialogDescription>
                    Pushes to a matching branch and matching tags deploy here
                    automatically. Leave both empty to deploy only manually.
                </DialogDescription>
                <Form
                    {...update.form([project, environment.id])}
                    transform={(data) => ({
                        name: data.name,
                        triggers: {
                            branch: data.branch === '' ? null : data.branch,
                            tagPattern:
                                data.tagPattern === '' ? null : data.tagPattern,
                        },
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="environment-edit-name">
                                    Name
                                </Label>
                                <Input
                                    id="environment-edit-name"
                                    name="name"
                                    defaultValue={environment.name}
                                    maxLength={63}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            {environment.kind !== 'preview' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="environment-edit-branch">
                                            Branch
                                        </Label>
                                        <Input
                                            id="environment-edit-branch"
                                            name="branch"
                                            defaultValue={
                                                environment.triggers.branch ??
                                                ''
                                            }
                                            placeholder="main, develop or release/*"
                                            className="type-mono"
                                            autoComplete="off"
                                            spellCheck={false}
                                        />
                                        <InputError
                                            message={
                                                errors['triggers.branch'] ??
                                                errors.triggers
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="environment-edit-tags">
                                            Tags
                                        </Label>
                                        <Input
                                            id="environment-edit-tags"
                                            name="tagPattern"
                                            defaultValue={
                                                environment.triggers
                                                    .tagPattern ?? ''
                                            }
                                            placeholder="v*"
                                            className="type-mono"
                                            autoComplete="off"
                                            spellCheck={false}
                                        />
                                        <InputError
                                            message={
                                                errors['triggers.tagPattern']
                                            }
                                        />
                                        <p className="type-small text-muted-foreground">
                                            To release production by tag only,
                                            clear the branch and set tags to{' '}
                                            <code className="type-mono-small">
                                                v*
                                            </code>
                                            .
                                        </p>
                                    </div>
                                </>
                            )}
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteEnvironmentDialog({
    project,
    environment,
    open,
    onOpenChange,
}: {
    project: string;
    environment: Settings;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [skipFinalBackup, setSkipFinalBackup] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const backedUp = environment.servicesWithBackups;
    const ready = name === environment.name;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                setName('');
                setSkipFinalBackup(false);
                setErrors({});
            }}
        >
            <DialogContent>
                <DialogTitle>Delete {environment.name}</DialogTitle>
                <DialogDescription>
                    This stops {environment.services}{' '}
                    {environment.services === 1 ? 'service' : 'services'} in{' '}
                    {environment.name} and removes the environment with its
                    deployments, variables and domains. It can’t be undone.
                </DialogDescription>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setProcessing(true);
                        router.delete(destroy([project, environment.id]).url, {
                            data: { confirmName: name, skipFinalBackup },
                            onSuccess: () => onOpenChange(false),
                            onError: (failed) => setErrors(failed),
                            onFinish: () => setProcessing(false),
                        });
                    }}
                >
                    <p className="type-small text-muted-foreground">
                        {backedUp > 0
                            ? `${backedUp} ${backedUp === 1 ? 'service has' : 'services have'} backups: a final backup of their data is taken first, and ${environment.name} is deleted once it succeeded. Snapshots stay in your backup destination; data volumes are deleted after the retention period.`
                            : 'Data volumes stay on their servers for the retention period, then are deleted.'}
                    </p>
                    {backedUp > 0 && environment.canSkipFinalBackup && (
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="skip-environment-final-backup"
                                checked={skipFinalBackup}
                                onCheckedChange={(checked) =>
                                    setSkipFinalBackup(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor="skip-environment-final-backup"
                                className="type-body font-normal"
                            >
                                Delete without a final backup (recorded in the
                                audit log). Only the existing snapshots remain.
                            </Label>
                        </div>
                    )}
                    <InputError message={errors.environment} />
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-environment-name">
                            Type{' '}
                            <span className="font-mono">
                                {environment.name}
                            </span>{' '}
                            to confirm
                        </Label>
                        <Input
                            id="confirm-environment-name"
                            value={name}
                            autoComplete="off"
                            spellCheck={false}
                            onChange={(event) => setName(event.target.value)}
                        />
                        <InputError
                            message={errors.confirmName ?? errors.environment}
                        />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={!ready || processing}
                        >
                            Delete environment
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EnvironmentRow({
    project,
    environment,
    production,
}: {
    project: string;
    environment: Settings;
    production: Settings | undefined;
}) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const triggers = triggerSummary(environment.triggers);

    return (
        <li className="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card">
            <div className="flex items-start justify-between gap-3 px-5 pt-4 pb-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <span className="flex items-center gap-2">
                        <Link
                            href={canvas([project, environment.id])}
                            className="truncate type-heading hover:underline"
                        >
                            {environment.name}
                        </Link>
                        {environment.kind !== 'persistent' && (
                            <Badge variant="outline">
                                {kindLabels[environment.kind]}
                            </Badge>
                        )}
                    </span>
                    <span className="type-small text-muted-foreground">
                        {triggers ? (
                            <>
                                Deploys on push to{' '}
                                <span className="type-mono-small text-foreground">
                                    {triggers}
                                </span>
                            </>
                        ) : (
                            'Deploys only when you deploy'
                        )}
                    </span>
                    {environment.autoDeployPaused && (
                        <span className="flex items-center gap-1 type-small text-status-progress">
                            <Pause aria-hidden="true" className="size-3.5" />
                            Auto-deploy paused after a rollback
                        </span>
                    )}
                </div>
                <div className="-mt-1 -mr-2 shrink-0">
                    {environment.canUpdate && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    aria-label={`Actions for ${environment.name}`}
                                >
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuItem
                                    onSelect={() => setEditing(true)}
                                >
                                    Rename and triggers
                                </DropdownMenuItem>
                                {production &&
                                    environment.id !== production.id && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.visit(
                                                    promote(project, {
                                                        query: {
                                                            from: environment.id,
                                                            to: production.id,
                                                        },
                                                    }),
                                                )
                                            }
                                        >
                                            Compare with {production.name}
                                        </DropdownMenuItem>
                                    )}
                                {environment.autoDeployPaused && (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            router.post(
                                                resume([
                                                    project,
                                                    environment.id,
                                                ]).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Resume auto-deploy
                                    </DropdownMenuItem>
                                )}
                                {environment.canDelete && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            disabled={
                                                environment.backupInFlight ||
                                                environment.pendingDeletion !==
                                                    null
                                            }
                                            onSelect={() => setDeleting(true)}
                                        >
                                            Delete environment
                                        </DropdownMenuItem>
                                    </>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                    <EditEnvironmentDialog
                        project={project}
                        environment={environment}
                        open={editing}
                        onOpenChange={setEditing}
                    />
                    {environment.canDelete && (
                        <DeleteEnvironmentDialog
                            project={project}
                            environment={environment}
                            open={deleting}
                            onOpenChange={setDeleting}
                        />
                    )}
                </div>
            </div>
            {environment.pendingDeletion !== null && (
                <div className="px-5 pb-4">
                    <PendingDeletion request={environment.pendingDeletion} />
                </div>
            )}
            <div className="mt-auto flex items-center justify-between gap-3 border-t bg-recessed px-5 py-2.5 type-small text-muted-foreground">
                <span>
                    {environment.revision === null ? (
                        'Never deployed'
                    ) : (
                        <>
                            Revision {environment.revision} ·{' '}
                            <TimeAgo value={environment.lastDeployedAt} />
                        </>
                    )}
                </span>
                <span className="tabular-nums">
                    {environment.services}{' '}
                    {environment.services === 1 ? 'service' : 'services'}
                </span>
            </div>
        </li>
    );
}

/**
 * `/p/{project}/settings/environments` (docs/08 §2): environments as cards
 * (name, what deploys it, live revision, services) with rename/triggers,
 * compare and delete in the ⋯ menu; "New environment" opens a dialog that
 * copies another environment.
 */
export function EnvironmentsSettings({
    project,
    environments,
    canCreate,
}: {
    project: App.Data.Canvas.ProjectData;
    environments: Settings[];
    canCreate: boolean;
}) {
    const copyable = environments.filter(
        (environment) => environment.kind !== 'preview',
    );
    const production = environments.find(
        (environment) => environment.kind === 'production',
    );
    const [base, setBase] = useState(copyable[0]?.id ?? '');
    const [creating, setCreating] = useState(false);

    return (
        <Section
            title="All environments"
            description="Each one is a full copy with its own variables, domains and data."
            actions={
                canCreate && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setCreating(true)}
                    >
                        <Plus aria-hidden="true" />
                        New environment
                    </Button>
                )
            }
        >
            <ul className="grid gap-4 sm:grid-cols-2">
                {environments.map((environment) => (
                    <EnvironmentRow
                        key={environment.id}
                        project={project.id}
                        environment={environment}
                        production={production}
                    />
                ))}
            </ul>

            {canCreate && (
                <Dialog open={creating} onOpenChange={setCreating}>
                    <DialogContent>
                        <DialogTitle>New environment</DialogTitle>
                        <DialogDescription>
                            Services, settings and variables are copied;
                            databases start empty with new credentials.
                        </DialogDescription>
                        <Form
                            {...environmentRoutes.store.form(project.id)}
                            transform={(data) => ({
                                name: data.name,
                                baseEnvironmentId: base || null,
                                triggers:
                                    data.branch === ''
                                        ? null
                                        : {
                                              branch: data.branch,
                                              tagPattern: null,
                                          },
                            })}
                            onSuccess={() => setCreating(false)}
                            className="flex flex-col gap-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="environment-name">
                                            Name
                                        </Label>
                                        <Input
                                            id="environment-name"
                                            name="name"
                                            required
                                            maxLength={63}
                                            placeholder="staging"
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="environment-base">
                                            Copy from
                                        </Label>
                                        <Select
                                            value={base}
                                            onValueChange={setBase}
                                        >
                                            <SelectTrigger
                                                id="environment-base"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {copyable.map((environment) => (
                                                    <SelectItem
                                                        key={environment.id}
                                                        value={environment.id}
                                                    >
                                                        {environment.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={errors.baseEnvironmentId}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="environment-branch">
                                            Deploy on push to (optional)
                                        </Label>
                                        <Input
                                            id="environment-branch"
                                            name="branch"
                                            placeholder="develop"
                                            className="type-mono"
                                            autoComplete="off"
                                            spellCheck={false}
                                        />
                                        <InputError
                                            message={errors['triggers.branch']}
                                        />
                                    </div>
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="secondary"
                                            >
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Create environment
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </Section>
    );
}
