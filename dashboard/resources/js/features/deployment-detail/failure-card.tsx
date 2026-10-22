import { Link, router } from '@inertiajs/react';
import { CircleX } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { shortSha } from '@/features/deployments/format';
import { fix } from '@/routes/deployments';
import {
    section as serviceSection,
    show as serviceTab,
} from '@/routes/projects/services';
import { CopyButton } from './overview-card';

type Detail = App.Data.Deployments.DeploymentDetailData;
type Explanation = App.Data.Deployments.FailureExplanationData;

/** A command in the fix text ("Run pnpm install locally…"), for "Copy command". */
function commandIn(text: string): string | null {
    const match = /\bRun (.+?) locally\b/.exec(text);

    return match ? match[1] : null;
}

export type FixAction = {
    label: string;
    run: (done?: () => void) => void;
    href?: string;
};

/**
 * The one-click fix of a known failure (flows §6): `set_port` and
 * `raise_health_timeout` stage the setting and redeploy (POST …/fix);
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
            const href = serviceTab(
                {
                    project: deployment.projectId,
                    environment: deployment.environmentId,
                    service: deployment.serviceId,
                    tab: 'variables',
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
    onJump,
    canFix,
}: {
    detail: Detail;
    errorLine: number | null;
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
                <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                    <h2 id="failure-title" className="type-heading">
                        {explanation
                            ? `${failureVerb(failure.class)}: ${lower(title)}`
                            : title}
                    </h2>
                    <span className="rounded-full border px-2 py-px type-mono-small text-muted-foreground">
                        {failure.class}
                    </span>
                </div>
                <div className="flex max-w-[72ch] flex-col gap-1.5 type-body text-muted-foreground">
                    {serving && <p className="text-foreground">{serving}</p>}
                    {explanation ? (
                        <>
                            <p>{explanation.cause}</p>
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
                                    label="Copy command"
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
