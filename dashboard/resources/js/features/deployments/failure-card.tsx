import { Link, router } from '@inertiajs/react';
import { CircleX } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { fix } from '@/routes/deployments';
import { section as serviceSection } from '@/routes/projects/services';
import { shortSha } from '@/lib/format';
import { CopyButton } from '@/components/copy-button';
import { cn } from '@/lib/utils';

type Detail = App.Data.Deployments.DeploymentDetailData;

export type QuotedLine = { number: number; text: string };
type Explanation = App.Data.Deployments.FailureExplanationData;

/** A command in the fix text ("Run pnpm install locally…"), for "Copy command". */
function commandIn(text: string): string | null {
    const match = /\bRun (.+?) locally\b/.exec(text);

    return match ? match[1] : null;
}

type FixAction = {
    label: string;
    run: (done?: () => void) => void;
    href?: string;
};

/**
 * The one-click fix of a known failure (flows §6): `set_port`,
 * `raise_health_timeout`, `use_tcp_healthcheck`, `accept_4xx`,
 * `add_capabilities` stage the setting and redeploy (POST …/fix);
 * `fix_volume_owner` redeploys; `free_disk` frees space on the server;
 * `add_variable` opens the service's variables with the name filled in;
 * `generate_dockerfile` opens Settings → Build with the guided sheet.
 */
export function fixAction(detail: Detail, canFix: boolean): FixAction | null {
    const explanation = detail.failureExplanation;
    const deployment = detail.deployment;
    const args = {
        project: deployment.projectId,
        environment: deployment.environmentId,
        deployment: deployment.id,
    };

    if (!explanation?.action || !canFix) {
        return null;
    }

    const params = explanation.params;

    switch (explanation.action) {
        case 'set_port': {
            const port = Number(params.port);

            return Number.isFinite(port) && port > 0
                ? {
                      label: `Use port ${port} and redeploy`,
                      run: (done) =>
                          router.post(
                              fix(args).url,
                              {
                                  action: 'set_port',
                                  port,
                              },
                              { onFinish: done },
                          ),
                  }
                : null;
        }
        case 'raise_health_timeout': {
            const seconds = Number(params.suggestedSeconds ?? 60);

            return {
                label: `Allow ${seconds}s to start and redeploy`,
                run: (done) =>
                    router.post(
                        fix(args).url,
                        {
                            action: 'raise_health_timeout',
                            seconds,
                        },
                        { onFinish: done },
                    ),
            };
        }
        case 'generate_dockerfile': {
            // docs/20 §3: Settings → Build opens the guided sheet.
            const href = serviceSection(
                {
                    project: deployment.projectId,
                    environment: deployment.environmentId,
                    service: deployment.serviceId,
                    section: 'build',
                },
                {
                    query: {
                        generate: String(params.trigger ?? 'plan_failed'),
                    },
                },
            ).url;

            return {
                label: 'Generate a Dockerfile',
                href,
                run: () => router.visit(href),
            };
        }
        case 'add_variable': {
            const name = String(params.name ?? '');
            const href = serviceSection(
                {
                    project: deployment.projectId,
                    environment: deployment.environmentId,
                    service: deployment.serviceId,
                    section: 'variables',
                },
                {
                    query: {
                        add: name,
                        ...(params.buildTime ? { buildTime: '1' } : {}),
                        ...(params.type === 'secret' ? { type: 'secret' } : {}),
                        ...(params.value
                            ? { value: String(params.value) }
                            : {}),
                    },
                },
            ).url;

            return {
                label: `Set up ${name}`,
                href,
                run: () => router.visit(href),
            };
        }
        case 'use_tcp_healthcheck':
        case 'accept_4xx':
        case 'add_capabilities':
        case 'fix_volume_owner': {
            const action = explanation.action;

            return {
                label: redeployLabel(action, params),
                run: (done) =>
                    router.post(fix(args).url, { action }, { onFinish: done }),
            };
        }
        case 'free_disk': {
            const server = String(params.server ?? '');

            return server
                ? {
                      label: `Free space on ${String(params.serverName ?? 'the server')}`,
                      run: (done) =>
                          router.post(
                              fix(args).url,
                              { action: 'free_disk', server },
                              { onFinish: done, preserveScroll: true },
                          ),
                  }
                : null;
        }
        default:
            return null;
    }
}

/** The button of a fix that stages a setting and redeploys. */
function redeployLabel(
    action: App.Enums.FailureFixAction,
    params: Explanation['params'],
): string {
    switch (action) {
        case 'use_tcp_healthcheck':
            return 'Check over TCP and redeploy';
        case 'accept_4xx':
            return 'Accept 4xx and redeploy';
        case 'add_capabilities':
            return 'Allow it to switch users and redeploy';
        case 'fix_volume_owner':
            return params.uid
                ? `Give new volumes to user ${String(params.uid)} and redeploy`
                : 'Give new volumes to the app’s user and redeploy';
        default:
            return 'Fix and redeploy';
    }
}

/**
 * Explained failure (Laravel error page, direction §9.7): a human title,
 * the reassurance, cause and fix in plain sentences, then actions (the
 * one-click fix when the catalogue has one, jump to the error line, copy
 * the command). Unknown failures fall back to the failure class's hint and
 * the raw first line. Never a red border around the card: the icon and
 * title carry the state.
 */
export function FailureCard({
    detail,
    errorLine,
    excerpt = [],
    onJump,
    canFix,
}: {
    detail: Detail;
    errorLine: number | null;
    /** The error line with a line of context around it, quoted in the card (DPL-2). */
    excerpt?: QuotedLine[];
    onJump: (line: number) => void;
    canFix: boolean;
}) {
    const failure = detail.failure;
    const explanation: Explanation | null = detail.failureExplanation;
    const action = fixAction(detail, canFix);
    const [pending, setPending] = useState(false);

    if (!failure) {
        return null;
    }

    const title = explanation?.title ?? failure.title;
    const command = explanation ? commandIn(explanation.fix) : null;
    const serving = detail.serving
        ? `Your site is still serving ${shortSha(detail.serving.commitSha) ?? 'the previous version'}.`
        : null;

    return (
        <section
            role="alert"
            aria-labelledby="failure-title"
            className="flex gap-4 rounded-[14px] border bg-card p-5 shadow-card"
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-status-error-soft text-status-error max-sm:hidden">
                <CircleX aria-hidden="true" className="size-[18px]" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                <div className="flex items-start justify-between gap-x-4">
                    <h2 id="failure-title" className="type-heading">
                        {explanation
                            ? `${failureVerb(failure.class)}: ${lower(title)}`
                            : title}
                    </h2>
                    {/* The failure class and the raw message, for a bug
                        report or a support thread. */}
                    <CopyButton
                        value={[failure.class, failure.message]
                            .filter(Boolean)
                            .join('\n')}
                        label="failure details"
                        size="xs"
                    />
                </div>
                <div className="flex max-w-[72ch] flex-col gap-1.5 type-body text-muted-foreground">
                    {serving && <p className="text-foreground">{serving}</p>}
                    {explanation ? (
                        <>
                            <p>{explanation.cause}</p>
                            {explanation.answer && (
                                <figure className="flex flex-col gap-1">
                                    <figcaption>The app answered:</figcaption>
                                    <blockquote className="rounded-md border bg-recessed px-2.5 py-1.5 type-mono-small break-words whitespace-pre-wrap text-foreground">
                                        {explanation.answer}
                                    </blockquote>
                                </figure>
                            )}
                            <p>{explanation.fix}</p>
                        </>
                    ) : (
                        <>
                            {failure.message && (
                                <p className="type-mono-small break-words whitespace-pre-wrap text-foreground">
                                    {failure.message.split('\n')[0]}
                                </p>
                            )}
                            <p>{failure.hint}</p>
                        </>
                    )}
                </div>
                {excerpt.length > 0 && (
                    <pre
                        aria-label={`Log around line ${errorLine ?? ''}`}
                        className="overflow-x-auto rounded-lg border bg-recessed py-2 type-mono-small leading-5"
                    >
                        {excerpt.map((line) => {
                            const current = line.number === errorLine;

                            return (
                                <div
                                    key={line.number}
                                    className={cn(
                                        'flex min-w-max',
                                        current && 'bg-status-error-soft',
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className="w-12 shrink-0 pr-3 text-right text-muted-foreground select-none"
                                    >
                                        {line.number}
                                    </span>
                                    <span
                                        className={cn(
                                            'pr-4',
                                            current
                                                ? 'text-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {current && (
                                            <span className="sr-only">
                                                Error:{' '}
                                            </span>
                                        )}
                                        {line.text || ' '}
                                    </span>
                                </div>
                            );
                        })}
                    </pre>
                )}
                {(action || errorLine !== null || command) && (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        {action &&
                            (action.href ? (
                                <Button size="sm" asChild>
                                    <Link href={action.href}>
                                        {action.label}
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    size="sm"
                                    disabled={pending}
                                    onClick={() => {
                                        setPending(true);
                                        action.run(() => setPending(false));
                                    }}
                                >
                                    {action.label}
                                </Button>
                            ))}
                        {errorLine !== null && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onJump(errorLine)}
                            >
                                Jump to line {errorLine}
                            </Button>
                        )}
                        {command && (
                            <span className="inline-flex items-center gap-1 rounded-md border bg-(--recessed) py-0.5 pr-0.5 pl-2.5">
                                <code className="type-mono-small">
                                    {command}
                                </code>
                                <CopyButton
                                    value={command}
                                    label="command"
                                    size="xs"
                                />
                            </span>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}

/** Lowercases a leading ordinary word; names such as BuildKit or GitHub keep their case. */
function lower(text: string): string {
    return /^[A-Z](?![A-Za-z0-9]*[A-Z])[a-z]*\b/.test(text)
        ? text.charAt(0).toLowerCase() + text.slice(1)
        : text;
}

/** "Build failed", "Health check failed" … the outcome before the explanation. */
function failureVerb(failureClass: App.Enums.DeploymentFailureClass): string {
    switch (failureClass) {
        case 'plan_error':
        case 'build_error':
        case 'build_timeout':
            return 'Build failed';
        case 'release_error':
            return 'Release failed';
        case 'health_timeout':
        case 'port_mismatch':
            return 'Health check failed';
        case 'runtime_error':
            return 'Start failed';
        default:
            return 'Deploy failed';
    }
}
