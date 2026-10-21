import { ArrowUpRight } from 'lucide-react';

type Problem = App.Data.ConfigAsCode.ConfigFileProblemData;

/** `https://github.com/acme/shop/blob/<sha>/launchroom.json#L14` */
export function fileLineUrl(
    repository: string | null,
    commitSha: string | null,
    path: string,
    line: number | null,
): string | null {
    if (!repository || !commitSha) {
        return null;
    }

    const file = path.split('/').map(encodeURIComponent).join('/');

    return `https://github.com/${repository}/blob/${commitSha}/${file}${line ? `#L${line}` : ''}`;
}

/**
 * One launchroom.json problem (docs/18 §6): where, what, what to do, and
 * the lines around it with the line marked and a caret under the column.
 */
export function ConfigProblem({
    problem,
    path,
    href,
    tone = 'error',
}: {
    problem: Problem;
    path: string;
    href?: string | null;
    tone?: 'error' | 'warning' | 'muted';
}) {
    const marker =
        tone === 'error'
            ? 'bg-status-error-soft'
            : tone === 'warning'
              ? 'bg-status-progress-soft'
              : 'bg-hover';

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className="type-body font-medium text-foreground">
                    {problem.message}
                </p>
                <span className="flex items-center gap-2 type-mono-small text-muted-foreground">
                    {problem.line !== null && (
                        <span>
                            {path}:{problem.line}:{problem.column}
                        </span>
                    )}
                    {href && (
                        <a
                            href={href}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="inline-flex items-center gap-0.5 rounded-sm font-sans type-small text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Open on GitHub
                            <ArrowUpRight
                                aria-hidden="true"
                                className="size-3.5"
                            />
                        </a>
                    )}
                </span>
            </div>
            {problem.excerpt.length > 0 && (
                <pre
                    aria-label={`${path} around line ${problem.line}`}
                    className="overflow-x-auto rounded-lg border bg-recessed py-2 type-mono-small leading-5"
                >
                    {problem.excerpt.map((line) => {
                        const current = line.number === problem.line;

                        return (
                            <div key={line.number}>
                                <div
                                    className={`flex min-w-max ${current ? marker : ''}`}
                                >
                                    <span
                                        aria-hidden="true"
                                        className="w-10 shrink-0 pr-3 text-right text-muted-foreground select-none"
                                    >
                                        {line.number}
                                    </span>
                                    <span
                                        className={
                                            current
                                                ? 'pr-4 text-foreground'
                                                : 'pr-4 text-muted-foreground'
                                        }
                                    >
                                        {line.text || ' '}
                                    </span>
                                </div>
                                {current && problem.column !== null && (
                                    <div
                                        aria-hidden="true"
                                        className="flex h-3 min-w-max leading-3"
                                    >
                                        <span className="w-10 shrink-0" />
                                        <span
                                            className={
                                                tone === 'error'
                                                    ? 'text-status-error'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {' '.repeat(
                                                Math.max(0, problem.column - 1),
                                            )}
                                            ^
                                        </span>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </pre>
            )}
            {problem.hint && (
                <p className="type-small text-muted-foreground">
                    {problem.hint}
                </p>
            )}
        </div>
    );
}
