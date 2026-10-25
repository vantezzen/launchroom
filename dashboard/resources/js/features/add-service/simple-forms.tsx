import { useState } from 'react';
import type { FormEvent } from 'react';
import { Check } from 'lucide-react';
import { Logo } from '@/components/logo';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ServiceGlyph } from '@/features/canvas/service-glyph';
import { parseArgv } from '@/features/projects/argv';
import { cn } from '@/lib/utils';
import { Field, suggestName } from './fields';
import type { SubmitService } from './types';

type FormProps = {
    submit: SubmitService;
    processing: boolean;
    errors: Record<string, string>;
};

/**
 * A prebuilt image: reference + port (mirrored and digest-pinned on deploy).
 */
export function ImageForm({ submit, processing, errors }: FormProps) {
    const [image, setImage] = useState('');
    const [name, setName] = useState('');
    const [nameTouched, setNameTouched] = useState(false);
    const [port, setPort] = useState('');

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit({
            kind: 'image',
            name: name || suggestName(image),
            image,
            port: port === '' ? null : Number(port),
        });
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-6">
            <Field
                id="add-image-reference"
                label="Image"
                helper="A tag or a digest, e.g. ghcr.io/umami-software/umami:postgresql-latest."
                error={errors.image ?? errors.reference ?? errors.source}
            >
                <Input
                    id="add-image-reference"
                    required
                    autoFocus
                    spellCheck={false}
                    value={image}
                    placeholder="nginx:1.27"
                    className="font-mono text-[13px]"
                    onChange={(event) => {
                        setImage(event.target.value.trim());
                        if (!nameTouched) {
                            setName(suggestName(event.target.value.trim()));
                        }
                    }}
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id="add-image-name"
                    label="Service name"
                    error={errors.name}
                >
                    <Input
                        id="add-image-name"
                        required
                        maxLength={63}
                        value={name}
                        onChange={(event) => {
                            setName(event.target.value);
                            setNameTouched(true);
                        }}
                    />
                </Field>
                <Field
                    id="add-image-port"
                    label="Port"
                    helper="Leave empty for a background process."
                    error={errors.port}
                >
                    <Input
                        id="add-image-port"
                        inputMode="numeric"
                        value={port}
                        placeholder="80"
                        className="tabular-nums"
                        onChange={(event) =>
                            setPort(event.target.value.replace(/\D/g, ''))
                        }
                    />
                </Field>
            </div>
            <div className="flex justify-end">
                <Button type="submit" disabled={processing || image === ''}>
                    Add service
                </Button>
            </div>
        </form>
    );
}

/**
 * A database engine with a version (credentials and a volume per environment).
 */
export function DatabaseForm({
    submit,
    processing,
    errors,
    engines,
    consumers = [],
}: FormProps & {
    engines: App.Data.Canvas.DatabaseEngineOptionData[];
    /** Services that can use the new database ("Connect it to…"). */
    consumers?: { id: string; name: string; logo: string }[];
}) {
    const [connectTo, setConnectTo] = useState<string[]>(() =>
        consumers.length === 1 ? [consumers[0].id] : [],
    );
    const [engine, setEngine] = useState(engines[0]);
    const [version, setVersion] = useState(engines[0]?.defaultVersion ?? '');
    const [name, setName] = useState<string>(engines[0]?.engine ?? '');
    const [nameTouched, setNameTouched] = useState(false);

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();

        if (engine) {
            submit({
                kind: 'database',
                name,
                engine: engine.engine,
                version,
                connectTo: connectTo.length > 0 ? connectTo : undefined,
            });
        }
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-6">
            <fieldset className="grid gap-2">
                <legend className="mb-2 type-label">Engine</legend>
                <div
                    role="radiogroup"
                    className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    {engines.map((option) => (
                        <button
                            key={option.engine}
                            type="button"
                            role="radio"
                            aria-checked={engine?.engine === option.engine}
                            onClick={() => {
                                setEngine(option);
                                setVersion(option.defaultVersion);
                                if (!nameTouched) {
                                    setName(option.engine);
                                }
                            }}
                            className={cn(
                                'flex min-h-24 flex-col items-center justify-center gap-2.5 rounded-xl border bg-card p-4 text-center transition-[border-color,box-shadow] duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                                engine?.engine === option.engine
                                    ? 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]'
                                    : 'hover:border-border-strong',
                            )}
                        >
                            <ServiceGlyph logo={option.engine} />
                            <span className="flex flex-col gap-0.5">
                                <span className="type-label">
                                    {option.label}
                                </span>
                                {option.tagline && (
                                    <span className="type-small text-muted-foreground">
                                        {option.tagline}
                                    </span>
                                )}
                            </span>
                        </button>
                    ))}
                </div>
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id="add-db-version"
                    label="Version"
                    error={errors.version ?? errors.source}
                >
                    <Select value={version} onValueChange={setVersion}>
                        <SelectTrigger id="add-db-version" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {engine?.versions.map((item) => (
                                <SelectItem key={item} value={item}>
                                    {engine.label} {item}
                                    {item === engine.defaultVersion && (
                                        <span className="ml-2 text-muted-foreground">
                                            recommended
                                        </span>
                                    )}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    id="add-db-name"
                    label="Service name"
                    error={errors.name}
                >
                    <Input
                        id="add-db-name"
                        required
                        maxLength={63}
                        value={name}
                        onChange={(event) => {
                            setName(event.target.value);
                            setNameTouched(true);
                        }}
                    />
                </Field>
            </div>
            {consumers.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-1 type-label">Connect it to…</legend>
                    <p className="-mt-1 mb-1 type-small text-muted-foreground">
                        The services you pick get its connection variables with
                        the next deploy.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {consumers.map((consumer) => {
                            const checked = connectTo.includes(consumer.id);

                            return (
                                <button
                                    key={consumer.id}
                                    type="button"
                                    role="checkbox"
                                    aria-checked={checked}
                                    onClick={() =>
                                        setConnectTo((current) =>
                                            checked
                                                ? current.filter(
                                                      (id) =>
                                                          id !== consumer.id,
                                                  )
                                                : [...current, consumer.id],
                                        )
                                    }
                                    className={cn(
                                        'inline-flex h-9 items-center gap-2 rounded-full border bg-card pr-3 pl-1.5 type-label transition-[border-color,box-shadow] duration-fast focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                                        checked
                                            ? 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]'
                                            : 'text-muted-foreground hover:border-border-strong',
                                    )}
                                >
                                    <Logo
                                        kind={consumer.logo}
                                        name={consumer.name}
                                        size={20}
                                        decorative
                                    />
                                    {consumer.name}
                                    {checked && (
                                        <Check
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                    )}
                                </button>
                            );
                        })}
                    </div>
                    {(errors.connectTo ?? errors['connectTo.0']) && (
                        <p className="type-small text-status-error">
                            {errors.connectTo ?? errors['connectTo.0']}
                        </p>
                    )}
                </fieldset>
            )}
            <p className="max-w-[60ch] type-small text-muted-foreground">
                Credentials are generated per environment and stored as secrets.
                {consumers.length > 0
                    ? ' You can connect more services on the canvas later.'
                    : ' Connect a service to it on the canvas to inject them.'}
            </p>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={processing || !engine || name === ''}
                >
                    Add database
                </Button>
            </div>
        </form>
    );
}

/**
 * A worker or cron: the image of an app service with its own command.
 */
export function ProcessForm({
    kind,
    submit,
    processing,
    errors,
    parents,
}: FormProps & {
    kind: 'worker' | 'cron';
    parents: { id: string; name: string }[];
}) {
    const [parent, setParent] = useState(parents[0]?.id ?? '');
    const [name, setName] = useState(
        kind === 'worker' ? 'worker' : 'scheduler',
    );
    const [command, setCommand] = useState('');
    const [commandError, setCommandError] = useState<string | null>(null);

    if (parents.length === 0) {
        return (
            <p className="max-w-[60ch] type-body text-muted-foreground">
                {kind === 'worker' ? 'Workers' : 'Crons'} run the image of an
                app built from a repository. Add a GitHub repository first.
            </p>
        );
    }

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        const argv = parseArgv(command);

        if (argv === null || argv.length === 0) {
            setCommandError(
                argv === null
                    ? 'A quote is not closed.'
                    : 'Enter the command to run.',
            );

            return;
        }

        setCommandError(null);
        submit({ kind, name, parentServiceId: parent, startCommand: argv });
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field
                    id="add-process-parent"
                    label="App"
                    error={errors.parentServiceId}
                >
                    <Select value={parent} onValueChange={setParent}>
                        <SelectTrigger
                            id="add-process-parent"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {parents.map((item) => (
                                <SelectItem key={item.id} value={item.id}>
                                    {item.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    id="add-process-name"
                    label="Service name"
                    error={errors.name}
                >
                    <Input
                        id="add-process-name"
                        required
                        maxLength={63}
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                </Field>
            </div>
            <Field
                id="add-process-command"
                label="Command"
                helper={
                    kind === 'worker'
                        ? 'Runs without a shell, e.g. php artisan queue:work --tries=3.'
                        : 'Runs without a shell, e.g. php artisan schedule:run. Set when it runs under Settings → Schedule. Without a schedule, the command runs continuously.'
                }
                error={commandError ?? errors.startCommand}
            >
                <Input
                    id="add-process-command"
                    required
                    autoFocus
                    spellCheck={false}
                    value={command}
                    className="font-mono text-[13px]"
                    placeholder={
                        kind === 'worker'
                            ? 'php artisan queue:work'
                            : 'php artisan schedule:run'
                    }
                    onChange={(event) => setCommand(event.target.value)}
                />
            </Field>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={processing || parent === '' || command === ''}
                >
                    Add {kind}
                </Button>
            </div>
        </form>
    );
}
