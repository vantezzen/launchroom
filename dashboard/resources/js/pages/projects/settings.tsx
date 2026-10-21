import { Head, router, useForm } from '@inertiajs/react';
import { Page, PageHeader } from '@/components/patterns/page';
import { SettingsNav } from '@/components/patterns/settings-nav';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import InputError from '@/components/input-error';
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
import { useState } from 'react';
import { PendingDeletion } from '@/features/backups/pending-deletion';
import { EnvironmentsSettings } from '@/features/environments/environments-settings';
import { ConfigFileSettings } from '@/features/config-as-code/config-file-settings';
import { SecretsPolicy } from '@/features/environments/secrets-policy';
import { LogRetentionSettings } from '@/features/logs/log-retention-settings';
import { PreviewSettingsSection } from '@/features/previews/preview-settings-section';
import { ProjectIconRow } from '@/features/projects/project-icon-row';
import type { ProjectShellProps } from '@/features/projects/types';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { destroy, settings } from '@/routes/projects';
import { update } from '@/routes/projects/settings';

type Deletion = {
    servicesWithBackups: number;
    inFlight: boolean;
    services: number;
    environments: number;
    pending: App.Data.Backups.DeletionRequestData | null;
    canSkipFinalBackup: boolean;
};

type Props = ProjectShellProps & {
    section: 'general' | 'environments' | 'previews' | 'logs' | 'danger';
    icon: App.Data.Projects.ProjectIconData;
    previewSettings: App.Data.Previews.PreviewSettingsData | null;
    allEnvironments: App.Data.Canvas.EnvironmentData[];
    environmentSettings: App.Data.Environments.EnvironmentSettingsData[];
    logRetention: App.Data.Logs.EnvironmentLogRetentionData[];
    logRetentionOptions: { days: number[]; maxMb: number[] };
    secretsPolicy: { project: boolean; team: boolean };
    configFile: App.Data.ConfigAsCode.ConfigFilePageData | null;
    canCreateEnvironment: boolean;
    canDelete: boolean;
    deletion: Deletion | null;
};

const sections = [
    ['general', 'General'],
    ['environments', 'Environments'],
    ['previews', 'Previews'],
    ['logs', 'Logs'],
    ['danger', 'Danger zone'],
] as const;

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

function DeleteProjectDialog({
    project,
    deletion,
    open,
    onOpenChange,
}: {
    project: App.Data.Canvas.ProjectData;
    deletion: Deletion;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [name, setName] = useState('');
    const [skipFinalBackup, setSkipFinalBackup] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const backedUp = deletion.servicesWithBackups;
    const ready = name === project.name;

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
                <DialogTitle>Delete {project.name}</DialogTitle>
                <DialogDescription>
                    This stops and removes{' '}
                    {plural(deletion.services, 'service', 'services')} in{' '}
                    {plural(
                        deletion.environments,
                        'environment',
                        'environments',
                    )}
                    , with their deployments, variables and domains. It can’t be
                    undone.
                </DialogDescription>
                <form
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setProcessing(true);
                        router.delete(destroy(project.id).url, {
                            data: { confirmName: name, skipFinalBackup },
                            onSuccess: () => onOpenChange(false),
                            onError: (failed) => setErrors(failed),
                            onFinish: () => setProcessing(false),
                        });
                    }}
                >
                    <p className="type-small text-muted-foreground">
                        {backedUp > 0
                            ? `${plural(backedUp, 'service has', 'services have')} backups: a final backup of their data is taken first, and the project is deleted once it succeeded. Snapshots stay in your backup destination; data volumes are deleted after the retention period.`
                            : 'Data volumes stay on their servers for the retention period, then are deleted.'}
                    </p>
                    {backedUp > 0 && deletion.canSkipFinalBackup && (
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="skip-final-backup"
                                checked={skipFinalBackup}
                                onCheckedChange={(checked) =>
                                    setSkipFinalBackup(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor="skip-final-backup"
                                className="type-body font-normal"
                            >
                                Delete without a final backup (recorded in the
                                audit log). Only the existing snapshots remain.
                            </Label>
                        </div>
                    )}
                    <InputError message={errors.project} />
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-project-name">
                            Type{' '}
                            <span className="font-mono">{project.name}</span> to
                            confirm
                        </Label>
                        <Input
                            id="confirm-project-name"
                            value={name}
                            autoComplete="off"
                            spellCheck={false}
                            onChange={(event) => setName(event.target.value)}
                        />
                        <InputError message={errors.confirmName} />
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
                            Delete project
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DangerZone({
    project,
    canDelete,
    deletion,
}: {
    project: App.Data.Canvas.ProjectData;
    canDelete: boolean;
    deletion: Deletion | null;
}) {
    const [open, setOpen] = useState(false);

    if (!canDelete || deletion === null) {
        return (
            <p className="type-body text-muted-foreground">
                Deleting a project needs the developer role or higher.
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {deletion.pending !== null && (
                <PendingDeletion request={deletion.pending} />
            )}
            <SettingsCard>
                <SettingsRow
                    label="Delete project"
                    description={
                        deletion.pending !== null
                            ? 'Waiting for its final backup (see above).'
                            : deletion.inFlight
                              ? 'A backup or restore is running. Wait for it to finish.'
                              : `Stops ${plural(deletion.services, 'service', 'services')} in ${plural(deletion.environments, 'environment', 'environments')} and removes the project.`
                    }
                >
                    <Button
                        variant="destructive"
                        size="sm"
                        disabled={
                            deletion.inFlight || deletion.pending !== null
                        }
                        onClick={() => setOpen(true)}
                    >
                        Delete project
                    </Button>
                </SettingsRow>
            </SettingsCard>
            <DeleteProjectDialog
                project={project}
                deletion={deletion}
                open={open}
                onOpenChange={setOpen}
            />
        </div>
    );
}

/**
 * Name and slug as settings rows; editing the name shows the floating
 * unsaved bar instead of a Save button (design-system §13.9).
 */
function General({
    project,
    icon,
    canManage,
}: {
    project: App.Data.Canvas.ProjectData;
    icon: App.Data.Projects.ProjectIconData;
    canManage: boolean;
}) {
    const form = useForm({ name: project.name });
    const save = () =>
        form.patch(update(project.id).url, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults({ name: form.data.name }),
        });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
        >
            <SettingsCard title="Project">
                <SettingsRow
                    label="Name"
                    description="Shown in launchroom only."
                    htmlFor="project-name"
                >
                    <div className="flex w-full flex-col gap-1 sm:w-72">
                        <Input
                            id="project-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={100}
                            required
                            disabled={!canManage}
                        />
                        <InputError message={form.errors.name} />
                    </div>
                </SettingsRow>
                <ProjectIconRow
                    project={project}
                    icon={icon}
                    canManage={canManage}
                />
                <SettingsRow
                    label="Slug"
                    description="Used in generated domains; it never changes, so links stay stable."
                >
                    <span className="type-mono text-muted-foreground">
                        {project.slug}
                    </span>
                </SettingsRow>
            </SettingsCard>
            <UnsavedBar
                open={form.isDirty}
                onReset={() => form.reset()}
                onSave={save}
                saving={form.processing}
            />
        </form>
    );
}

export default function ProjectSettings({
    project,
    section,
    icon,
    environmentSettings,
    logRetention,
    logRetentionOptions,
    secretsPolicy,
    configFile,
    canCreateEnvironment,
    canDelete,
    deletion,
    abilities,
    previewSettings,
}: Props) {
    return (
        <>
            <Head title={`Settings · ${project.name}`} />
            <Page
                size="settings"
                nav={
                    <SettingsNav
                        label="Project settings"
                        groups={[
                            {
                                items: sections.map(([id, label]) => ({
                                    title: label,
                                    href: settings({
                                        project: project.id,
                                        section: id,
                                    }),
                                    active: section === id,
                                })),
                            },
                        ]}
                    />
                }
            >
                <PageHeader
                    title={
                        sections.find(([id]) => id === section)?.[1] ??
                        'Settings'
                    }
                />
                <div className="w-full">
                    {section === 'general' && (
                        <div className="flex flex-col gap-10">
                            <General
                                project={project}
                                icon={icon}
                                canManage={abilities.manage}
                            />
                            <SecretsPolicy
                                project={project.id}
                                policy={secretsPolicy}
                                canManage={abilities.manage}
                            />
                            {configFile && (
                                <ConfigFileSettings
                                    project={project.id}
                                    page={configFile}
                                />
                            )}
                        </div>
                    )}
                    {section === 'danger' && (
                        <DangerZone
                            project={project}
                            canDelete={canDelete}
                            deletion={deletion}
                        />
                    )}
                    {section === 'environments' && (
                        <EnvironmentsSettings
                            project={project}
                            environments={environmentSettings}
                            canCreate={canCreateEnvironment}
                        />
                    )}
                    {section === 'logs' && (
                        <LogRetentionSettings
                            project={project.id}
                            environments={logRetention}
                            options={logRetentionOptions}
                        />
                    )}
                    {section === 'previews' && previewSettings && (
                        <PreviewSettingsSection
                            project={project.id}
                            settings={previewSettings}
                        />
                    )}
                </div>
            </Page>
        </>
    );
}
