import { Head, router, useForm } from '@inertiajs/react';
import {
    FileCode2,
    GitPullRequest,
    Layers,
    ScrollText,
    SlidersHorizontal,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { Page } from '@/components/patterns/page';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import { buildSettingsIndex } from '@/components/settings/search';
import type { SettingDraft } from '@/components/settings/search';
import { SettingsPage } from '@/components/settings/settings-page';
import { SettingsShell } from '@/components/settings/settings-shell';
import type { SettingsNavGroup } from '@/components/settings/types';
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

type Section =
    | 'general'
    | 'environments'
    | 'previews'
    | 'config-file'
    | 'logs'
    | 'danger';

type Props = ProjectShellProps & {
    section: Section;
    /** `/p/{project}/settings` without a section: the list on phones. */
    index: boolean;
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

/**
 * Project settings pages (docs/08 §2): General on top, then Environments
 * (environments, previews), Configuration (config file, log retention) and
 * the Danger zone.
 */
function projectGroups(
    project: App.Data.Canvas.ProjectData,
    environments: App.Data.Canvas.EnvironmentData[],
): SettingsNavGroup<Section>[] {
    const lasting = environments.filter(
        (environment) => environment.kind !== 'preview',
    );
    const previews = environments.length - lasting.length;

    return [
        {
            id: 'general',
            label: null,
            pages: [
                {
                    id: 'general',
                    label: 'General',
                    icon: SlidersHorizontal,
                    summary: project.name,
                    keywords: ['name', 'icon', 'slug', 'secrets'],
                },
            ],
        },
        {
            id: 'environments',
            label: 'Environments',
            pages: [
                {
                    id: 'environments',
                    label: 'Environments',
                    icon: Layers,
                    summary: lasting
                        .map((environment) => environment.name)
                        .join(', '),
                    keywords: ['staging', 'production', 'branch', 'triggers'],
                },
                {
                    id: 'previews',
                    label: 'Previews',
                    icon: GitPullRequest,
                    summary:
                        previews === 0
                            ? 'An environment per pull request'
                            : plural(
                                  previews,
                                  'preview environment',
                                  'preview environments',
                              ),
                    keywords: ['pull request', 'pr', 'preview environments'],
                },
            ],
        },
        {
            id: 'configuration',
            label: 'Configuration',
            pages: [
                {
                    id: 'config-file',
                    label: 'Config file',
                    icon: FileCode2,
                    summary: 'Settings from launchroom.json',
                    keywords: ['launchroom.json', 'config as code', 'json'],
                },
                {
                    id: 'logs',
                    label: 'Logs',
                    icon: ScrollText,
                    summary: 'How long runtime logs are kept',
                    keywords: ['retention', 'keep logs', 'runtime logs'],
                },
            ],
        },
        {
            id: 'danger',
            label: null,
            pages: [
                {
                    id: 'danger',
                    label: 'Danger zone',
                    icon: TriangleAlert,
                    summary: 'Delete the project',
                    keywords: ['delete', 'remove'],
                },
            ],
        },
    ];
}

/** The rows search finds besides the pages (`setting` anchors). */
const drafts: SettingDraft<Section>[] = [
    {
        target: 'general',
        label: 'Name',
        hint: 'Rename the project',
        keywords: ['rename', 'title'],
        anchor: 'name',
    },
    {
        target: 'general',
        label: 'Icon',
        hint: 'The app’s favicon or an upload',
        keywords: ['favicon', 'logo', 'image', 'upload'],
        anchor: 'icon',
    },
    {
        target: 'general',
        label: 'Slug',
        hint: 'Used in generated domains',
        keywords: ['identifier', 'url'],
        anchor: 'slug',
    },
    {
        target: 'general',
        label: 'Production secrets must differ',
        hint: 'Previews can’t reuse production secrets',
        keywords: ['secrets', 'policy', 'variables'],
        anchor: 'secrets',
    },
    {
        target: 'environments',
        label: 'New environment',
        hint: 'A full copy with its own variables and data',
        keywords: ['add environment', 'create', 'staging'],
    },
    {
        target: 'previews',
        label: 'Preview environments',
        hint: 'Turn previews on or off',
        keywords: ['enable', 'disable', 'pull requests'],
    },
    {
        target: 'previews',
        label: 'Which pull requests',
        hint: 'Base environment, branches, label, limits',
        keywords: ['label', 'target branch', 'concurrent', 'expiry'],
    },
    {
        target: 'previews',
        label: 'Pull requests from forks',
        hint: 'No previews, or after your approval',
        keywords: ['fork', 'approval', 'external'],
    },
    {
        target: 'previews',
        label: 'Access',
        hint: 'Who can open a preview',
        keywords: ['protection', 'login', 'password', 'production data'],
    },
    {
        target: 'previews',
        label: 'Databases in previews',
        hint: 'Empty, a copy, or shared',
        keywords: ['database', 'clone', 'seed', 'data'],
    },
    {
        target: 'previews',
        label: 'Automated tests',
        hint: 'Bypass secret for CI',
        keywords: ['ci', 'bypass', 'secret', 'playwright'],
    },
    {
        target: 'config-file',
        label: 'Read the config file',
        hint: 'Path and repository of launchroom.json',
        keywords: ['path', 'repository', 'schema'],
    },
    {
        target: 'config-file',
        label: 'Last read',
        hint: 'Per environment, with Download and Check now',
        keywords: ['download', 'check now', 'problems'],
    },
    {
        target: 'logs',
        label: 'Keep runtime logs',
        hint: 'Days and size per environment',
        keywords: ['retention', 'days', 'size', 'storage'],
    },
    {
        target: 'danger',
        label: 'Delete project',
        hint: 'Stop every service and remove the project',
        keywords: ['delete', 'remove', 'destroy'],
        anchor: 'delete-project',
    },
];

/** One sentence under the page title, where the page has no intro of its own. */
const descriptions: Partial<Record<Section, string>> = {
    general: 'The project’s name, icon and secrets policy.',
    previews:
        'Pull request previews: which ones run, who can open them and their data.',
    'config-file': 'Read settings from launchroom.json in your repository.',
    danger: 'Deleting a project can’t be undone.',
};

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
                            ? `${plural(backedUp, 'service has', 'services have')} backups. A final backup of their data is taken before the project is deleted. Snapshots stay in your backup destination, and data volumes are deleted after the retention period.`
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
                    setting="delete-project"
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
                    setting="name"
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
                    setting="slug"
                    label="Slug"
                    description="Used in generated domains. It can’t be changed, so links keep working."
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
    index,
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
    allEnvironments,
}: Props) {
    const groups = projectGroups(project, allEnvironments);
    const page = groups
        .flatMap((group) => group.pages)
        .find((entry) => entry.id === section);

    return (
        <>
            <Head title={`Settings - ${project.name}`} />
            <Page size="settings">
                <SettingsShell
                    label="Project settings"
                    groups={groups}
                    current={section}
                    requested={index ? null : section}
                    hrefFor={(id) =>
                        settings({
                            project: project.id,
                            section: id ?? undefined,
                        }).url
                    }
                    index={buildSettingsIndex(drafts, groups, { pages: true })}
                    emptyHint="Try “environment”, “preview” or “delete”."
                >
                    <SettingsPage
                        title={page?.label ?? 'Settings'}
                        description={descriptions[section]}
                    >
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
                            </div>
                        )}
                        {section === 'config-file' && configFile && (
                            <ConfigFileSettings
                                project={project.id}
                                page={configFile}
                            />
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
                    </SettingsPage>
                </SettingsShell>
            </Page>
        </>
    );
}
