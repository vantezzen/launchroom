import { router } from '@inertiajs/react';
import { ArrowUpRight, DatabaseZap, Info, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { MetaLine } from '@/components/patterns/meta-line';
import { SheetSection } from '@/components/patterns/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { BlueprintCanvas } from '@/features/blueprints/blueprint-canvas';
import { RepositoryField } from './repository-field';
import type { RepositoryChoice } from './repository-field';

type Template = App.Data.Templates.TemplateSummaryData;

function memoryLabel(megabytes: number): string {
    return megabytes >= 1024
        ? `${Number((megabytes / 1024).toFixed(1))} GB`
        : `${megabytes} MB`;
}

/**
 * A template's detail and guided setup (docs/17 §4): what you get (the
 * canvas it creates), what it needs, and only the few inputs that matter.
 * Used in the gallery's sheet and in the canvas' add sheet.
 */
export function TemplateSetup({
    template,
    url,
    askName,
    submitLabel,
    onDone,
}: {
    template: Template;
    url: string;
    /** New projects are named; catalog additions go into the open project. */
    askName: boolean;
    submitLabel: string;
    onDone?: () => void;
}) {
    const [name, setName] = useState(template.name);
    const [inputs, setInputs] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            template.inputs
                .filter((input) => input.type !== 'repository')
                .map((input) => [
                    input.id,
                    input.type === 'text' ? (input.placeholder ?? '') : '',
                ]),
        ),
    );
    const [repository, setRepository] = useState<RepositoryChoice | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const generated = template.preview.services.flatMap((service) =>
        service.variables
            .filter((variable) => variable.generated && variable.secret)
            .map((variable) => variable.key),
    );
    // Moving from another server: its data goes in before the first deploy (docs/14 §12.9).
    const database = template.preview.services.find(
        (service) => service.kind === 'database',
    );
    const fields = template.inputs.filter(
        (input) => input.type !== 'repository',
    );
    const general = Object.entries(errors).find(
        ([key]) => !['name', 'repository', 'branch'].includes(key),
    )?.[1];

    const submit = (deployNow: boolean) => {
        setProcessing(true);
        router.post(
            url,
            {
                ...(askName ? { name } : {}),
                inputs,
                deploy: deployNow,
                repository: repository?.repository.id ?? null,
                branch: repository?.branch ?? null,
            },
            {
                onSuccess: () => onDone?.(),
                onError: (failed) => setErrors(failed),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <SheetSection>
                <p className="type-body text-muted-foreground">
                    {template.description}
                </p>
            </SheetSection>

            <SheetSection title="What you get">
                <BlueprintCanvas preview={template.preview} />
                {generated.length > 0 && (
                    <p className="flex items-start gap-2 type-small text-muted-foreground">
                        <KeyRound
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0"
                        />
                        <span>
                            {generated.join(', ')}{' '}
                            {generated.length === 1 ? 'is' : 'are'} generated
                            for you and stored as{' '}
                            {generated.length === 1 ? 'a secret' : 'secrets'}.
                        </span>
                    </p>
                )}
            </SheetSection>

            {template.notes.length > 0 && (
                <SheetSection title="Good to know">
                    <ul className="flex flex-col gap-2">
                        {template.notes.map((note) => (
                            <li
                                key={note}
                                className="flex items-start gap-2 type-small text-muted-foreground"
                            >
                                <Info
                                    aria-hidden="true"
                                    className="mt-0.5 size-3.5 shrink-0"
                                />
                                <span>{note}</span>
                            </li>
                        ))}
                    </ul>
                </SheetSection>
            )}

            <SheetSection title="Resources">
                <dl className="grid grid-cols-3 overflow-hidden rounded-xl border bg-recessed">
                    {[
                        ['CPU', `${Number(template.preview.cpus.toFixed(2))}`],
                        ['Memory', memoryLabel(template.preview.memoryMb)],
                        ['Volumes', String(template.preview.volumes)],
                    ].map(([label, value], index) => (
                        <div
                            key={label}
                            className={
                                'flex flex-col gap-0.5 px-4 py-3' +
                                (index > 0 ? ' border-l' : '')
                            }
                        >
                            <dt className="type-small text-muted-foreground">
                                {label}
                            </dt>
                            <dd className="type-heading tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
                <p className="type-small text-muted-foreground">
                    Limits per service. You can change them after it deploys.
                </p>
            </SheetSection>

            <SheetSection title="Set it up">
                <div className="flex flex-col gap-4">
                    {askName && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="template-name">Project name</Label>
                            <Input
                                id="template-name"
                                value={name}
                                maxLength={100}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                            />
                            {errors.name && (
                                <p className="type-small text-status-error">
                                    {errors.name}
                                </p>
                            )}
                        </div>
                    )}
                    {template.needsRepository && (
                        <div className="flex flex-col gap-1.5">
                            <Label>Repository</Label>
                            <RepositoryField
                                value={repository}
                                onChange={setRepository}
                                error={errors.repository ?? errors.branch}
                            />
                        </div>
                    )}
                    {fields.map((input) => (
                        <div key={input.id} className="flex flex-col gap-1.5">
                            <Label htmlFor={`input-${input.id}`}>
                                {input.label}
                                {input.optional && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        (optional)
                                    </span>
                                )}
                            </Label>
                            <Input
                                id={`input-${input.id}`}
                                type={input.type === 'email' ? 'email' : 'text'}
                                className={
                                    input.type === 'domain'
                                        ? 'font-mono'
                                        : undefined
                                }
                                placeholder={
                                    input.type === 'domain'
                                        ? 'app.example.com'
                                        : (input.placeholder ?? undefined)
                                }
                                value={inputs[input.id] ?? ''}
                                onChange={(event) =>
                                    setInputs((current) => ({
                                        ...current,
                                        [input.id]: event.target.value,
                                    }))
                                }
                            />
                            {input.description && (
                                <p className="type-small text-muted-foreground">
                                    {input.description}
                                </p>
                            )}
                        </div>
                    ))}
                    {general && (
                        <p className="type-body text-status-error">{general}</p>
                    )}
                </div>
            </SheetSection>

            {askName && database && (
                <SheetSection>
                    <p className="flex items-start gap-2 type-small text-muted-foreground">
                        <DatabaseZap
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0"
                        />
                        <span>
                            Moving existing data? Choose{' '}
                            <span className="text-foreground">
                                Create without deploying
                            </span>
                            , open {database.name} and choose{' '}
                            <span className="text-foreground">
                                Import data before the first deploy
                            </span>{' '}
                            in its Backups settings. The first deploy then
                            starts with your data.
                        </span>
                    </p>
                </SheetSection>
            )}

            <SheetSection>
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <MetaLine className="type-small text-muted-foreground">
                        {template.license}
                        {template.website ? (
                            <a
                                href={template.website}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-0.5 underline-offset-2 hover:text-foreground hover:underline"
                            >
                                {new URL(template.website).hostname}
                                <ArrowUpRight
                                    aria-hidden="true"
                                    className="size-3"
                                />
                            </a>
                        ) : (
                            'Built from your code'
                        )}
                    </MetaLine>
                    <div className="flex shrink-0 flex-wrap gap-2 sm:justify-end">
                        {askName && (
                            <Button
                                variant="outline"
                                onClick={() => submit(false)}
                                disabled={
                                    processing ||
                                    name.trim() === '' ||
                                    (template.needsRepository &&
                                        repository === null)
                                }
                            >
                                Create without deploying
                            </Button>
                        )}
                        <Button
                            onClick={() => submit(true)}
                            disabled={
                                processing ||
                                (askName && name.trim() === '') ||
                                (template.needsRepository &&
                                    repository === null)
                            }
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </div>
                </div>
            </SheetSection>
        </>
    );
}
