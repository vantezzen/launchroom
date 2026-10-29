import { ArrowRight, GitBranch, Link2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Logo, logoLabel } from '@/components/logo';
import { MetaLine } from '@/components/patterns/meta-line';
import { StatusIcon } from '@/components/status';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { requestJson, RequestFailed } from '@/features/projects/http';
import { cn } from '@/lib/utils';
import { addUrl, checkUrl } from '@/routes/projects/import';
import { parseRepositoryUrl } from './repository-url';

type Checked = App.Data.Git.PublicRepositoryData;
type Repository = App.Data.Git.GitRepositoryData;
type Template = App.Data.Templates.TemplateSummaryData;

type State =
    | { kind: 'idle' }
    | { kind: 'checking'; url: string }
    | { kind: 'checked'; url: string; result: Checked }
    | { kind: 'failed'; url: string; message: string }
    | { kind: 'continuing'; url: string; result: Checked };

const PROVIDER_NAME: Record<string, string> = {
    github: 'GitHub',
    gitlab: 'GitLab',
    codeberg: 'Codeberg',
    git: 'a git server',
};

/** The repository's place in one phrase: through a connection, or public. */
function whereItLives(result: Checked): string {
    const repository = result.repository;

    // Added by URL before: still the public repository, polled.
    if (!repository || (repository.provider === 'git' && !repository.private)) {
        return `Public on ${PROVIDER_NAME[result.provider]}`;
    }

    const name =
        repository.provider === 'github'
            ? 'GitHub'
            : repository.provider === 'git'
              ? PROVIDER_NAME[result.provider]
              : logoLabel(repository.serverKind);

    return repository.private
        ? `Private on ${name}, through ${result.via ?? 'your connection'}`
        : `Your repository on ${name}`;
}

/** What a template sets up for you, in the recommendation's words. */
function setsUp(template: Template): string {
    const services = template.preview.services;

    if (services.some((service) => service.kind === 'database')) {
        return 'its database and secrets';
    }

    return template.preview.volumes > 0
        ? 'its storage and secrets'
        : 'its settings and secrets';
}

function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

/**
 * "Paste a Git URL" (docs/19 §4.3): the hero field of New project. Live
 * grammar check and provider mark while typing; the repository is read
 * (branches, default branch, template match) when a URL is pasted, after a
 * pause on a complete URL, or on Enter. Continue adds it as a plain git
 * repository and hands it to the import flow. `/` focuses the field.
 */
export function RepositoryUrlField({
    templates,
    onContinue,
    onUseTemplate,
}: {
    templates: Template[];
    onContinue: (repository: Repository, branch: string | null) => void;
    onUseTemplate: (template: Template) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [value, setValue] = useState('');
    const [state, setState] = useState<State>({ kind: 'idle' });
    const [showError, setShowError] = useState(false);
    const parsed = useMemo(() => parseRepositoryUrl(value), [value]);
    const checkedFor = parsed.ok ? parsed.fullName : null;
    const current = 'url' in state && state.url === checkedFor ? state : null;
    const result =
        current?.kind === 'checked' || current?.kind === 'continuing'
            ? current.result
            : null;
    const template = result?.template
        ? (templates.find((item) => item.id === result.template) ?? null)
        : null;
    const ready = result !== null && result.problem === null;

    // `/` anywhere on the page focuses the field.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                event.key === '/' &&
                !event.metaKey &&
                !event.ctrlKey &&
                !event.altKey &&
                !isTyping(event.target)
            ) {
                event.preventDefault();
                input.current?.focus();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const check = (
        raw: string,
        signal?: AbortSignal,
    ): Promise<Checked | null> => {
        const target = parseRepositoryUrl(raw);

        if (!target.ok) {
            setShowError(true);

            return Promise.resolve(null);
        }

        setState({ kind: 'checking', url: target.fullName });

        return requestJson<Checked>(
            'get',
            checkUrl({ query: { url: raw.trim() } }).url,
            undefined,
            signal,
        )
            .then((checked) => {
                setState({
                    kind: 'checked',
                    url: target.fullName,
                    result: checked,
                });

                return checked;
            })
            .catch((failure: unknown) => {
                if (signal?.aborted) {
                    return null;
                }

                setState({
                    kind: 'failed',
                    url: target.fullName,
                    message:
                        failure instanceof RequestFailed
                            ? failure.message
                            : 'Checking the repository failed. Try again.',
                });

                return null;
            });
    };

    // Read the repository after a short pause on a complete URL.
    useEffect(() => {
        if (!parsed.ok || current) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(
            () => void check(value, controller.signal),
            900,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
        // `check` is recreated per render; the URL decides.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [checkedFor]);

    const proceed = (checked: Checked) => {
        if (checked.problem !== null) {
            return;
        }

        // A connection already reaches it: continue like Import on its row.
        if (checked.repository) {
            onContinue(
                checked.repository,
                checked.branch ?? checked.defaultBranch,
            );

            return;
        }

        setState({
            kind: 'continuing',
            url: checkedFor ?? '',
            result: checked,
        });
        requestJson<Repository>('post', addUrl().url, { url: checked.url })
            .then((repository) =>
                onContinue(repository, checked.branch ?? checked.defaultBranch),
            )
            .catch((failure: unknown) =>
                setState({
                    kind: 'failed',
                    url: checkedFor ?? '',
                    message:
                        failure instanceof RequestFailed
                            ? failure.message
                            : 'Adding the repository failed. Try again.',
                }),
            );
    };

    const submit = () => {
        if (!parsed.ok) {
            setShowError(true);

            return;
        }

        if (result && result.problem === null) {
            if (template) {
                onUseTemplate(template);
            } else {
                proceed(result);
            }

            return;
        }

        if (current?.kind === 'checking' || current?.kind === 'continuing') {
            return;
        }

        void check(value).then((checked) => {
            if (checked && checked.problem === null && !checked.template) {
                proceed(checked);
            }
        });
    };

    const mark = parsed.ok
        ? parsed.provider
        : (parsed.provider ?? (value.trim() === '' ? null : 'git'));
    const busy = current?.kind === 'checking' || current?.kind === 'continuing';
    const invalid = !parsed.ok && parsed.error !== '' && showError;
    const problem =
        current?.kind === 'failed'
            ? current.message
            : (result?.problem ?? null);

    return (
        <div className="flex flex-col gap-3">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className={cn(
                    'group/field flex h-16 items-center gap-3 rounded-[14px] border border-border-strong bg-card pr-2 pl-3 shadow-card transition-[border-color,box-shadow] duration-fast focus-within:border-foreground/30 focus-within:ring-2 focus-within:ring-signal/35 sm:pl-4',
                    (invalid || problem) &&
                        'border-status-error/60 focus-within:border-status-error/70 focus-within:ring-status-error/25',
                )}
            >
                <span
                    aria-hidden="true"
                    className="relative flex size-8 shrink-0 items-center justify-center"
                >
                    {mark ? (
                        <Logo
                            key={mark}
                            kind={mark}
                            size={32}
                            decorative
                            className="animate-enter"
                        />
                    ) : (
                        <Link2 className="size-5 text-muted-foreground" />
                    )}
                </span>
                <label htmlFor="repository-url" className="sr-only">
                    Git repository URL
                </label>
                <input
                    ref={input}
                    id="repository-url"
                    type="text"
                    inputMode="url"
                    autoComplete="off"
                    autoCapitalize="off"
                    autoCorrect="off"
                    spellCheck={false}
                    value={value}
                    placeholder="https://github.com/owner/app"
                    aria-invalid={invalid || problem ? true : undefined}
                    aria-describedby="repository-url-status"
                    onChange={(event) => {
                        setValue(event.target.value);
                        setShowError(false);
                    }}
                    onPaste={(event) => {
                        const pasted = event.clipboardData.getData('text');

                        if (pasted.trim() !== '' && value.trim() === '') {
                            event.preventDefault();
                            setValue(pasted.trim());
                            setShowError(true);
                            void check(pasted.trim());
                        }
                    }}
                    onBlur={() => value.trim() !== '' && setShowError(true)}
                    className="h-full min-w-0 flex-1 bg-transparent font-mono text-[15px] text-foreground outline-none placeholder:font-sans placeholder:text-faint sm:text-[16px]"
                />
                {value === '' && (
                    <kbd
                        aria-hidden="true"
                        className="hidden h-6 min-w-6 items-center justify-center rounded-md border px-1.5 font-mono text-[12px] text-muted-foreground group-focus-within/field:hidden sm:inline-flex"
                    >
                        /
                    </kbd>
                )}
                {!template && value.trim() !== '' && (
                    <Button
                        type="submit"
                        size="lg"
                        disabled={value.trim() === '' || busy}
                        className="h-11 shrink-0 px-3 sm:px-5"
                    >
                        {current?.kind === 'continuing' ? <Spinner /> : null}
                        <span className="hidden sm:inline">Continue</span>
                        <ArrowRight aria-hidden="true" className="sm:hidden" />
                    </Button>
                )}
            </form>

            <p
                id="repository-url-status"
                aria-live="polite"
                className="flex min-h-5 items-center gap-2 px-1 type-small text-muted-foreground"
            >
                {invalid ? (
                    <>
                        <StatusIcon state="failed" />
                        <span className="text-status-error">
                            {!parsed.ok && parsed.error}
                        </span>
                    </>
                ) : problem ? (
                    <>
                        <StatusIcon state="failed" />
                        <span className="text-status-error">{problem}</span>
                    </>
                ) : current?.kind === 'checking' ? (
                    <>
                        <Spinner className="size-3.5" />
                        Checking{' '}
                        <span className="truncate font-mono text-foreground">
                            {current.url}
                        </span>
                    </>
                ) : current?.kind === 'continuing' ? (
                    <>
                        <Spinner className="size-3.5" />
                        Adding{' '}
                        <span className="truncate font-mono text-foreground">
                            {current.url}
                        </span>
                    </>
                ) : ready && result ? (
                    <MetaLine className="min-w-0">
                        <span className="inline-flex items-center gap-1.5 text-foreground">
                            <StatusIcon state="active" />
                            {whereItLives(result)}
                        </span>
                        <span className="inline-flex items-center gap-1 font-mono">
                            <GitBranch
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {result.branch ?? result.defaultBranch}
                        </span>
                        {result.branches.length > 1 &&
                            (result.branches.length >= 100
                                ? '100+ branches'
                                : `${result.branches.length} branches`)}
                        <span>
                            {result.pushes === 'webhook'
                                ? 'Pushes deploy right away'
                                : 'New pushes are picked up every minute'}
                        </span>
                    </MetaLine>
                ) : parsed.ok ? (
                    <span>
                        Press Enter to check{' '}
                        <span className="font-mono text-foreground">
                            {parsed.fullName}
                        </span>
                    </span>
                ) : (
                    <span>
                        Any public repository on GitHub, GitLab, Codeberg or
                        your own git server.
                    </span>
                )}
            </p>

            {template && result && (
                <TemplateRecommendation
                    template={template}
                    continuing={current?.kind === 'continuing'}
                    canBuild={result.problem === null}
                    onUseTemplate={() => onUseTemplate(template)}
                    onBuild={() => proceed(result)}
                />
            )}
        </div>
    );
}

function TemplateRecommendation({
    template,
    continuing,
    canBuild,
    onUseTemplate,
    onBuild,
}: {
    template: Template;
    continuing: boolean;
    canBuild: boolean;
    onUseTemplate: () => void;
    onBuild: () => void;
}) {
    return (
        <div
            role="region"
            aria-label={`${template.name} template`}
            className="flex animate-enter flex-col gap-4 rounded-[14px] border bg-card p-4 shadow-card sm:flex-row sm:items-center sm:p-5"
        >
            <div className="flex min-w-0 flex-1 items-center gap-4">
                <Logo
                    kind={template.logo}
                    name={template.name}
                    size={44}
                    decorative
                />
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="type-heading">This is {template.name}.</p>
                    <p className="type-body text-muted-foreground">
                        The {template.name} template sets up {setsUp(template)}{' '}
                        for you.
                    </p>
                </div>
            </div>
            <div className="flex shrink-0 flex-wrap gap-2">
                {canBuild && (
                    <Button
                        variant="outline"
                        onClick={onBuild}
                        disabled={continuing}
                    >
                        {continuing && <Spinner />}
                        Build from source
                    </Button>
                )}
                <Button onClick={onUseTemplate}>Use the template</Button>
            </div>
        </div>
    );
}
