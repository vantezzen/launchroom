import { Head, router } from '@inertiajs/react';
import { FileUp, KeyRound, TriangleAlert } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { OptionCards } from '@/components/patterns/option-cards';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    BlueprintCanvas,
    resourcesLine,
} from '@/features/blueprints/blueprint-canvas';
import { NewProjectTabs } from '@/features/blueprints/new-project-tabs';
import { NoteList } from '@/features/blueprints/note-list';
import { plural } from '@/features/canvas/format';
import { requestJson, RequestFailed } from '@/features/projects/http';
import { RepositoryField } from '@/features/templates/repository-field';
import type { RepositoryChoice } from '@/features/templates/repository-field';
import { cn } from '@/lib/utils';
import {
    compose as composeRoute,
    importMethod,
    index as projectsIndex,
} from '@/routes/projects';
import { preview as previewRoute, store } from '@/routes/projects/compose';

type Preview = App.Data.Blueprints.ComposePreviewData;
type ServicePreview = App.Data.Blueprints.BlueprintServicePreviewData;

const EXAMPLE = `services:
  web:
    image: ghcr.io/umami-software/umami:3.4.0
    ports: ["3000:3000"]
    environment:
      DATABASE_URL: postgres://umami:\${DB_PASSWORD}@db:5432/umami
    depends_on: [db]
  db:
    image: postgres:17
    environment:
      POSTGRES_USER: umami
      POSTGRES_PASSWORD: \${DB_PASSWORD}
      POSTGRES_DB: umami
    volumes:
      - pgdata:/var/lib/postgresql/data
volumes:
  pgdata:
`;

/**
 * "Import a Compose file" (docs/17 §2.1): paste, upload or read the file
 * from a repository; the preview shows the canvas it becomes and what
 * happened to every line; then name it and deploy.
 */
export default function ImportCompose() {
    const [source, setSource] = useState<'paste' | 'repository'>('paste');
    const [compose, setCompose] = useState('');
    const [fileName, setFileName] = useState<string | null>(null);
    const [repository, setRepository] = useState<RepositoryChoice | null>(null);
    const [envFile, setEnvFile] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [files, setFiles] = useState<Record<string, string>>({});
    const [databases, setDatabases] = useState<
        Record<string, 'managed' | 'container'>
    >({});
    const [convertBindMounts, setConvertBindMounts] = useState(true);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);
    const [selected, setSelected] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [deploying, setDeploying] = useState(false);
    const upload = useRef<HTMLInputElement>(null);

    // Values typed for missing names go in as extra .env lines.
    const env = useMemo(
        () =>
            [
                envFile.trim(),
                ...Object.entries(values)
                    .filter(([, value]) => value !== '')
                    .map(([key, value]) => `${key}=${JSON.stringify(value)}`),
            ]
                .filter(Boolean)
                .join('\n'),
        [envFile, values],
    );
    const ready =
        source === 'paste' ? compose.trim() !== '' : repository !== null;
    const payload = useMemo(
        () => ({
            source,
            compose: source === 'paste' ? compose : null,
            repository:
                source === 'repository'
                    ? (repository?.repository.id ?? null)
                    : null,
            branch:
                source === 'repository' ? (repository?.branch ?? null) : null,
            envFile: env === '' ? null : env,
            files,
            databases,
            convertBindMounts,
        }),
        [source, compose, repository, env, files, databases, convertBindMounts],
    );

    useEffect(() => {
        if (!ready) {
            setPreview(null);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setLoading(true);
            requestJson<Preview>(
                'post',
                previewRoute().url,
                payload,
                controller.signal,
            )
                .then((data) => {
                    setPreview(data);
                    setFailure(null);

                    if (!nameTouched && data.suggestedName) {
                        // No `name:` in the file: its first public service names it.
                        const first =
                            data.preview?.services.find(
                                (service) => service.public,
                            ) ?? data.preview?.services[0];
                        setName(
                            data.suggestedName === 'compose'
                                ? (first?.name ?? '')
                                : data.suggestedName,
                        );
                    }
                })
                .catch((cause: unknown) => {
                    if (!controller.signal.aborted) {
                        setFailure(
                            cause instanceof RequestFailed
                                ? cause.message
                                : 'The preview didn’t load. Try again.',
                        );
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, 500);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
        // nameTouched only decides whether to fill the name.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [payload, ready]);

    const read = (file: File | undefined) => {
        if (!file) {
            return;
        }

        if (file.size > 256 * 1024) {
            setFailure(`${file.name} is larger than 256 KB.`);

            return;
        }

        void file.text().then((text) => {
            setCompose(text);
            setFileName(file.name);
        });
    };

    const deploy = (deployNow: boolean) => {
        setDeploying(true);
        router.post(
            store().url,
            { ...payload, name, deploy: deployNow },
            {
                onError: (failed) => setErrors(failed),
                onFinish: () => setDeploying(false),
            },
        );
    };

    const services = preview?.preview?.services ?? [];
    const missing =
        preview?.inputs.filter((input) => input.status === 'missing') ?? [];
    const generated =
        preview?.inputs.filter((input) => input.status === 'generated') ?? [];
    const refused = services.reduce(
        (sum, service) =>
            sum +
            service.notes.filter((note) => note.kind === 'refused').length,
        0,
    );
    const serverError = Object.values(errors)[0];

    return (
        <>
            <Head title="Import a Compose file" />
            <Page size="narrow">
                <PageHeader
                    title="Import a Compose file"
                    description="Paste your compose.yml to see what it becomes before anything is created."
                />
                <NewProjectTabs current="compose" />

                <Section title="Your file">
                    <OptionCards
                        label="Where the file is"
                        value={source}
                        onChange={(next) => setSource(next)}
                        options={[
                            {
                                value: 'paste',
                                title: 'Paste or upload',
                                description:
                                    'Images run as they are. Services with a build section need a repository.',
                            },
                            {
                                value: 'repository',
                                title: 'From a repository',
                                description:
                                    'Reads compose.yaml from a branch and builds its services from the code.',
                            },
                        ]}
                    />
                    {source === 'paste' ? (
                        <div className="flex flex-col gap-2">
                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="compose-file">
                                    {fileName ?? 'compose.yml'}
                                </Label>
                                <div className="flex gap-2">
                                    {compose === '' && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setCompose(EXAMPLE)}
                                        >
                                            Try an example
                                        </Button>
                                    )}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => upload.current?.click()}
                                    >
                                        <FileUp />
                                        Upload
                                    </Button>
                                    <input
                                        ref={upload}
                                        type="file"
                                        accept=".yml,.yaml,text/yaml"
                                        className="hidden"
                                        onChange={(event) =>
                                            read(event.target.files?.[0])
                                        }
                                    />
                                </div>
                            </div>
                            <Textarea
                                id="compose-file"
                                value={compose}
                                onChange={(event) => {
                                    setCompose(event.target.value);
                                    setFileName(null);
                                }}
                                onDragOver={(event) => event.preventDefault()}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    read(event.dataTransfer.files[0]);
                                }}
                                spellCheck={false}
                                placeholder="services:&#10;  web:&#10;    image: …"
                                className="[field-sizing:fixed] max-h-[480px] min-h-64 overflow-y-auto font-mono text-[13px] leading-5"
                                aria-invalid={errors.compose ? true : undefined}
                            />
                            {errors.compose && (
                                <p className="type-small text-status-error">
                                    {errors.compose}
                                </p>
                            )}
                        </div>
                    ) : (
                        <RepositoryField
                            value={repository}
                            onChange={setRepository}
                            error={errors.repository}
                        />
                    )}
                    <details
                        className="group rounded-xl border bg-card"
                        open={
                            missing.length > 0 || envFile !== ''
                                ? true
                                : undefined
                        }
                    >
                        <summary className="flex cursor-pointer list-none items-center gap-2 px-4 py-3 type-label">
                            <KeyRound
                                aria-hidden="true"
                                className="size-4 text-muted-foreground"
                            />
                            Values for {'${…}'}
                            <span className="ml-auto type-small font-normal text-muted-foreground">
                                Optional
                            </span>
                        </summary>
                        <div className="flex flex-col gap-2 border-t px-4 py-3">
                            <Textarea
                                aria-label="Values (.env)"
                                value={envFile}
                                onChange={(event) =>
                                    setEnvFile(event.target.value)
                                }
                                spellCheck={false}
                                placeholder="DB_PASSWORD=…"
                                className="min-h-24 font-mono text-[13px]"
                            />
                            <p className="type-small text-muted-foreground">
                                Used for the file’s {'${NAME}'} values and
                                env_file: .env. Secret-looking names you leave
                                out are generated.
                            </p>
                        </div>
                    </details>
                </Section>

                {(loading || failure || preview) && (
                    <Section
                        title="Here’s your project"
                        description={
                            preview?.preview ? (
                                <MetaLine>
                                    {plural(services.length, 'service')}
                                    {resourcesLine(preview.preview)}
                                    {refused > 0 &&
                                        `${refused} ${refused === 1 ? 'line' : 'lines'} not imported`}
                                </MetaLine>
                            ) : undefined
                        }
                        actions={loading ? <Spinner /> : undefined}
                    >
                        {failure && <Callout>{failure}</Callout>}
                        {preview?.error && (
                            <Callout>
                                {preview.error}
                                {preview.errorLine !== null && (
                                    <span className="type-mono-small">
                                        {' '}
                                        (line {preview.errorLine})
                                    </span>
                                )}
                            </Callout>
                        )}
                        {preview?.preview && (
                            <div className="rounded-[14px] border bg-card p-4 shadow-card">
                                <BlueprintCanvas
                                    preview={preview.preview}
                                    selected={selected}
                                    onSelect={(key) => {
                                        setSelected(key);
                                        document
                                            .getElementById(`service-${key}`)
                                            ?.scrollIntoView({
                                                behavior: 'smooth',
                                                block: 'center',
                                            });
                                    }}
                                />
                            </div>
                        )}
                        {preview?.path && (
                            <p className="type-small text-muted-foreground">
                                Read from{' '}
                                <span className="type-mono-small text-foreground">
                                    {preview.path}
                                </span>
                                .
                            </p>
                        )}
                    </Section>
                )}

                {preview?.preview && (
                    <Section
                        title="Line by line"
                        description="What launchroom does with each part of the file."
                    >
                        {(preview.notes.length > 0 ||
                            preview.skipped.length > 0) && (
                            <div className="flex flex-col gap-4 rounded-[14px] border bg-card p-5 shadow-card">
                                <span className="type-label">The file</span>
                                {preview.skipped.map((skipped) => (
                                    <div
                                        key={skipped.name}
                                        className="flex items-start gap-2.5"
                                    >
                                        <TriangleAlert
                                            aria-label="Not imported"
                                            className="text-status-warning mt-0.5 size-3.5 shrink-0"
                                        />
                                        <span className="min-w-0 flex-1 type-small">
                                            <span className="type-mono-small">
                                                {skipped.name}
                                            </span>{' '}
                                            isn’t imported: {skipped.reason}
                                            {skipped.needsRepository &&
                                                source === 'paste' && (
                                                    <button
                                                        type="button"
                                                        className="ml-1 text-signal underline-offset-2 hover:underline"
                                                        onClick={() =>
                                                            setSource(
                                                                'repository',
                                                            )
                                                        }
                                                    >
                                                        Import from a repository
                                                    </button>
                                                )}
                                        </span>
                                        {skipped.line !== null && (
                                            <span className="shrink-0 type-mono-small text-muted-foreground">
                                                line {skipped.line}
                                            </span>
                                        )}
                                    </div>
                                ))}
                                <NoteList notes={preview.notes} />
                            </div>
                        )}
                        {services.map((service) => (
                            <ServiceCard
                                key={service.key}
                                service={service}
                                selected={selected === service.key}
                                database={
                                    service.choice?.engine
                                        ? (databases[service.choice.service] ??
                                          (service.choice.managed
                                              ? 'managed'
                                              : 'container'))
                                        : null
                                }
                                onDatabase={(value) =>
                                    service.choice &&
                                    setDatabases((current) => ({
                                        ...current,
                                        [service.choice!.service]: value,
                                    }))
                                }
                                convertBindMounts={convertBindMounts}
                                onConvertBindMounts={setConvertBindMounts}
                            />
                        ))}
                    </Section>
                )}

                {preview?.preview &&
                    (generated.length > 0 ||
                        missing.length > 0 ||
                        preview.missingFiles.length > 0) && (
                        <Section
                            title="Values"
                            description="Only what the file leaves open."
                        >
                            <div className="flex flex-col divide-y rounded-[14px] border bg-card shadow-card">
                                {generated.map((input) => (
                                    <div
                                        key={input.name}
                                        className="flex items-center gap-3 px-5 py-3"
                                    >
                                        <span className="min-w-0 flex-1 truncate type-mono-small">
                                            {input.name}
                                        </span>
                                        <Badge variant="secondary">
                                            Generated
                                        </Badge>
                                    </div>
                                ))}
                                {missing.map((input) => (
                                    <div
                                        key={input.name}
                                        className="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center"
                                    >
                                        <Label
                                            htmlFor={`value-${input.name}`}
                                            className="min-w-0 flex-1 truncate type-mono-small"
                                        >
                                            {input.name}
                                            {input.required && (
                                                <span className="font-sans text-muted-foreground">
                                                    {' '}
                                                    (required
                                                    {input.message
                                                        ? `: ${input.message}`
                                                        : ''}
                                                    )
                                                </span>
                                            )}
                                        </Label>
                                        <Input
                                            id={`value-${input.name}`}
                                            className="sm:w-72"
                                            type={
                                                input.secret
                                                    ? 'password'
                                                    : 'text'
                                            }
                                            value={values[input.name] ?? ''}
                                            onChange={(event) =>
                                                setValues((current) => ({
                                                    ...current,
                                                    [input.name]:
                                                        event.target.value,
                                                }))
                                            }
                                            placeholder="Set it later in Variables"
                                        />
                                    </div>
                                ))}
                                {preview.missingFiles.map((file) => (
                                    <div
                                        key={file}
                                        className="flex flex-col gap-2 px-5 py-3"
                                    >
                                        <Label
                                            htmlFor={`file-${file}`}
                                            className="type-mono-small"
                                        >
                                            {file}
                                        </Label>
                                        <Textarea
                                            id={`file-${file}`}
                                            value={files[file] ?? ''}
                                            onChange={(event) =>
                                                setFiles((current) => ({
                                                    ...current,
                                                    [file]: event.target.value,
                                                }))
                                            }
                                            spellCheck={false}
                                            placeholder="Paste the file to import its variables"
                                            className="min-h-20 font-mono text-[13px]"
                                        />
                                    </div>
                                ))}
                            </div>
                        </Section>
                    )}

                {preview?.preview && (
                    <Section title="Name and deploy">
                        <div className="flex flex-col gap-5 rounded-[14px] border bg-card p-5 shadow-card">
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="project-name">
                                    Project name
                                </Label>
                                <Input
                                    id="project-name"
                                    value={name}
                                    maxLength={100}
                                    onChange={(event) => {
                                        setName(event.target.value);
                                        setNameTouched(true);
                                    }}
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                {errors.name && (
                                    <p className="type-small text-status-error">
                                        {errors.name}
                                    </p>
                                )}
                            </div>
                            {serverError && !errors.name && (
                                <p className="type-body text-status-error">
                                    {serverError}
                                </p>
                            )}
                            <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="type-small text-muted-foreground">
                                    {plural(services.length, 'service')} in
                                    production.
                                </p>
                                <div className="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                                    <Button
                                        variant="outline"
                                        onClick={() => deploy(false)}
                                        disabled={
                                            deploying ||
                                            loading ||
                                            name.trim() === ''
                                        }
                                    >
                                        Create without deploying
                                    </Button>
                                    <Button
                                        onClick={() => deploy(true)}
                                        disabled={
                                            deploying ||
                                            loading ||
                                            name.trim() === ''
                                        }
                                    >
                                        {deploying && <Spinner />}
                                        Create and deploy
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </Section>
                )}
            </Page>
        </>
    );
}

function Callout({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-start gap-3 rounded-xl border bg-card p-4">
            <TriangleAlert
                aria-hidden="true"
                className="text-status-warning mt-0.5 size-4 shrink-0"
            />
            <p className="type-body">{children}</p>
        </div>
    );
}

function ServiceCard({
    service,
    selected,
    database,
    onDatabase,
    convertBindMounts,
    onConvertBindMounts,
}: {
    service: ServicePreview;
    selected: boolean;
    database: 'managed' | 'container' | null;
    onDatabase: (value: 'managed' | 'container') => void;
    convertBindMounts: boolean;
    onConvertBindMounts: (value: boolean) => void;
}) {
    const counts = (['refused', 'warning'] as const)
        .map(
            (kind) =>
                [
                    kind,
                    service.notes.filter((note) => note.kind === kind).length,
                ] as const,
        )
        .filter(([, count]) => count > 0);
    const engine = service.choice?.engine;

    return (
        <article
            id={`service-${service.key}`}
            className={cn(
                'flex scroll-mt-24 flex-col gap-4 rounded-[14px] border bg-card p-5 shadow-card transition-[border-color,box-shadow] duration-base',
                selected &&
                    'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]',
            )}
        >
            <header className="flex items-center gap-3">
                <Logo
                    kind={service.logo}
                    name={service.name}
                    size={44}
                    decorative
                />
                <div className="flex min-w-0 flex-1 flex-col">
                    <h3 className="truncate type-heading">{service.name}</h3>
                    <MetaLine className="type-small text-muted-foreground">
                        {service.subtitle}
                        {service.public &&
                            service.port !== null &&
                            'Gets a domain'}
                        {service.variables.length > 0 &&
                            plural(service.variables.length, 'variable')}
                    </MetaLine>
                </div>
                {counts.map(([kind, count]) => (
                    <Badge
                        key={kind}
                        variant={kind === 'refused' ? 'destructive' : 'outline'}
                    >
                        {count} {kind === 'refused' ? 'refused' : 'to check'}
                    </Badge>
                ))}
            </header>
            {engine && database && (
                <OptionCards
                    label={`How to run ${service.name}`}
                    value={database}
                    onChange={onDatabase}
                    options={[
                        {
                            value: 'managed',
                            title: `Managed ${engine === 'mysql' ? 'MySQL' : engine === 'postgres' ? 'PostgreSQL' : engine === 'redis' ? 'Redis' : 'Valkey'}`,
                            description:
                                'Recommended: generated credentials, backups and clean stops.',
                        },
                        {
                            value: 'container',
                            title: 'Keep as a container',
                            description:
                                'Runs the image as it is, with a volume.',
                        },
                    ]}
                />
            )}
            {(service.choice?.bindMounts ?? 0) > 0 && (
                <label className="flex items-center justify-between gap-4 rounded-xl border bg-recessed px-4 py-3">
                    <span className="flex flex-col">
                        <span className="type-label">Convert to volumes</span>
                        <span className="type-small text-muted-foreground">
                            Folders from your machine become empty volumes on
                            the server.
                        </span>
                    </span>
                    <Switch
                        checked={convertBindMounts}
                        onCheckedChange={onConvertBindMounts}
                    />
                </label>
            )}
            <NoteList notes={service.notes} />
        </article>
    );
}

ImportCompose.layout = {
    breadcrumbs: [
        { title: 'Projects', href: projectsIndex() },
        { title: 'New project', href: importMethod() },
        { title: 'Import a Compose file', href: composeRoute() },
    ],
};
