import { router, useForm } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    Download,
    FileCode2,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { MetaDivider } from '@/components/patterns/meta-line';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import { TimeAgo } from '@/components/time-ago';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { CopyButton } from '@/features/deployment-detail/overview-card';
import { shortSha } from '@/features/deployments/format';
import {
    LearnMore,
    SettingsCard,
    SettingsRow,
    useDocsUrl,
} from '@/features/settings/settings-card';
import config from '@/routes/projects/config';
import { ConfigProblem, fileLineUrl } from './config-problem';

type Page = App.Data.ConfigAsCode.ConfigFilePageData;
type Row = App.Data.ConfigAsCode.ConfigFileEnvironmentData;

const AUTO = 'auto';

function Status({ row }: { row: Row }) {
    const status = row.status;
    const [Icon, tone, label] =
        status === null
            ? [CircleDashed, 'text-muted-foreground', 'Not read yet']
            : status === 'invalid'
              ? [CircleX, 'text-status-error', 'Has problems']
              : status === 'missing'
                ? [CircleDashed, 'text-muted-foreground', 'No file yet']
                : status === 'staged'
                  ? [CircleDashed, 'text-signal', 'Changes staged']
                  : [
                        CircleCheck,
                        'text-status-ok',
                        status === 'applied' ? 'Applied' : 'Up to date',
                    ];

    return (
        <span className={`inline-flex items-center gap-1.5 type-label ${tone}`}>
            <Icon aria-hidden="true" className="size-3.5" />
            {label}
        </span>
    );
}

function plural(count: number, word: string): string {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

function EnvironmentRow({
    project,
    row,
    path,
    repository,
}: {
    project: string;
    row: Row;
    path: string;
    repository: string | null;
}) {
    const [syncing, setSyncing] = useState(false);

    return (
        <li className="flex flex-col gap-4 px-5 py-4">
            <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="flex flex-wrap items-center gap-x-2.5 gap-y-1 type-body font-medium">
                        {row.name}
                        <Status row={row} />
                    </p>
                    <p className="flex flex-wrap items-center gap-x-1.5 type-small text-muted-foreground">
                        {row.commitSha ? (
                            <>
                                {row.fileUrl ? (
                                    <a
                                        href={row.fileUrl}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                        className="type-mono-small text-foreground underline-offset-4 hover:underline"
                                    >
                                        {shortSha(row.commitSha)}
                                    </a>
                                ) : (
                                    <span className="type-mono-small">
                                        {shortSha(row.commitSha)}
                                    </span>
                                )}
                                <MetaDivider />
                                <TimeAgo value={row.checkedAt} />
                            </>
                        ) : (
                            <span>Read at the next push</span>
                        )}
                        {row.managedSettings > 0 && (
                            <>
                                <MetaDivider />
                                <span>
                                    {plural(row.managedSettings, 'setting')} in{' '}
                                    {plural(row.managedServices, 'service')}
                                </span>
                            </>
                        )}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href={row.downloadUrl} download>
                            <Download aria-hidden="true" />
                            Download
                        </a>
                    </Button>
                    {row.canSync && repository && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={syncing}
                            onClick={() => {
                                setSyncing(true);
                                router.post(
                                    config.sync({
                                        project,
                                        environment: row.id,
                                    }).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setSyncing(false),
                                    },
                                );
                            }}
                        >
                            <RefreshCw
                                aria-hidden="true"
                                className={
                                    syncing
                                        ? 'motion-safe:animate-spin'
                                        : undefined
                                }
                            />
                            Check now
                        </Button>
                    )}
                </div>
            </div>
            {row.errors.length > 0 && (
                <ol className="flex flex-col gap-4 border-l-2 border-status-error-soft pl-4">
                    {row.errors.slice(0, 3).map((problem, index) => (
                        <li key={`${problem.path}-${index}`}>
                            <ConfigProblem
                                problem={problem}
                                path={path}
                                href={fileLineUrl(
                                    repository,
                                    row.commitSha,
                                    path,
                                    problem.line,
                                )}
                            />
                        </li>
                    ))}
                    {row.errors.length > 3 && (
                        <li className="type-small text-muted-foreground">
                            And {plural(row.errors.length - 3, 'more problem')}.
                        </li>
                    )}
                </ol>
            )}
            {row.errors.length === 0 && row.ignored.length > 0 && (
                <ul className="flex flex-col gap-1 type-small text-muted-foreground">
                    {row.ignored.map((note, index) => (
                        <li key={index}>– {note.message}</li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * Settings → Config file (docs/18 §3.1): read launchroom.json or
 * not, its path and repository, then each environment's last read with
 * Download and Check now. The `$schema` line for editor autocompletion sits
 * in the footer.
 */
export function ConfigFileSettings({
    project,
    page,
}: {
    project: string;
    page: Page;
}) {
    const form = useForm({
        enabled: page.settings.enabled,
        path: page.settings.path,
        repositoryId: page.settings.repositoryId,
    });
    const save = () =>
        form.patch(config.update(project).url, {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
        });
    const docsUrl = useDocsUrl();
    const schemaLine = `"$schema": "${page.schemaUrl}"`;
    const needsRepository =
        form.data.enabled &&
        page.repositories.length > 1 &&
        form.data.repositoryId === null;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                save();
            }}
            className="flex flex-col gap-10"
        >
            <SettingsCard
                title="Config file"
                description={
                    <>
                        Service settings in a launchroom.json next to your code,
                        read at every push.{' '}
                        <LearnMore href={docsUrl('config-as-code')}>
                            launchroom.json reference
                        </LearnMore>
                    </>
                }
                footer={
                    <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <span>Autocompletion in your editor:</span>
                        <code className="min-w-0 type-mono-small break-all text-foreground">
                            {schemaLine}
                        </code>
                        <CopyButton
                            value={schemaLine}
                            label="Copy the $schema line"
                        />
                    </span>
                }
            >
                <SettingsRow
                    label="Follow launchroom.json"
                    htmlFor="config-enabled"
                    inline
                >
                    <Switch
                        id="config-enabled"
                        checked={form.data.enabled}
                        disabled={!page.canUpdate}
                        onCheckedChange={(checked) =>
                            form.setData('enabled', checked)
                        }
                    />
                </SettingsRow>
                <SettingsRow
                    label="Path"
                    description="Relative to the repository root."
                    htmlFor="config-path"
                >
                    <div className="flex w-full flex-col gap-1 sm:w-72">
                        <Input
                            id="config-path"
                            value={form.data.path}
                            onChange={(event) =>
                                form.setData('path', event.target.value)
                            }
                            disabled={!page.canUpdate || !form.data.enabled}
                            className="font-mono text-[13px]"
                            spellCheck={false}
                            autoComplete="off"
                        />
                        <InputError message={form.errors.path} />
                    </div>
                </SettingsRow>
                <SettingsRow
                    label="Repository"
                    description={
                        page.repositories.length === 0
                            ? 'Add an app from a repository first.'
                            : needsRepository
                              ? 'Your apps come from several repositories: pick the one with the file.'
                              : 'Where the file lives.'
                    }
                    htmlFor="config-repository"
                >
                    {page.repositories.length > 1 ? (
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Select
                                value={
                                    form.data.repositoryId === null
                                        ? AUTO
                                        : String(form.data.repositoryId)
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'repositoryId',
                                        value === AUTO ? null : Number(value),
                                    )
                                }
                                disabled={!page.canUpdate || !form.data.enabled}
                            >
                                <SelectTrigger
                                    id="config-repository"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Pick a repository" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={AUTO}>
                                        Pick a repository
                                    </SelectItem>
                                    {page.repositories.map((repository) => (
                                        <SelectItem
                                            key={repository.id}
                                            value={String(repository.id)}
                                        >
                                            {repository.fullName}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.repositoryId} />
                        </div>
                    ) : (
                        <span className="flex items-center gap-2 type-mono text-muted-foreground">
                            <FileCode2 aria-hidden="true" className="size-4" />
                            {page.repositories[0]?.fullName ?? 'None yet'}
                        </span>
                    )}
                </SettingsRow>
            </SettingsCard>

            <SettingsCard
                title="Last read"
                description="Each environment reads the file of the commit it deploys."
            >
                <ul className="divide-y">
                    {page.environments.map((row) => (
                        <EnvironmentRow
                            key={row.id}
                            project={project}
                            row={row}
                            path={page.settings.path}
                            repository={page.repository}
                        />
                    ))}
                </ul>
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
