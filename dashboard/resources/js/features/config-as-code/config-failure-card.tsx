import { FileWarning } from 'lucide-react';
import { shortSha } from '@/features/deployments/format';
import { ConfigProblem, fileLineUrl } from './config-problem';

type Detail = App.Data.Deployments.DeploymentDetailData;

/**
 * A push that failed on its launchroom.json (docs/18 §6): the reassurance,
 * then one problem per block with the lines around it. No red frame.
 */
export function ConfigFailureCard({ detail }: { detail: Detail }) {
    const config = detail.configErrors;

    if (!config) {
        return null;
    }

    const count = config.errors.length;
    const serving = detail.serving
        ? `Nothing changed: ${shortSha(detail.serving.commitSha) ?? 'the previous version'} is still serving.`
        : 'Nothing was deployed or staged.';

    return (
        <section
            role="alert"
            aria-labelledby="config-failure-title"
            className="flex gap-4 rounded-[14px] border bg-card p-5 shadow-card"
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-status-error-soft text-status-error max-sm:hidden">
                <FileWarning aria-hidden="true" className="size-[18px]" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                        <h2 id="config-failure-title" className="type-heading">
                            {config.path} has{' '}
                            {count === 1 ? 'a problem' : `${count} problems`}
                        </h2>
                        <span className="rounded-full border px-2 py-px type-mono-small text-muted-foreground">
                            config_error
                        </span>
                    </div>
                    <p className="max-w-[72ch] type-body text-muted-foreground">
                        <span className="text-foreground">{serving}</span> Fix
                        the file and push again; launchroom reads it at every
                        push
                        {config.commitSha
                            ? ` (this one: ${shortSha(config.commitSha)})`
                            : ''}
                        .
                    </p>
                </div>
                <ol className="flex flex-col gap-5">
                    {config.errors.map((problem, index) => (
                        <li
                            key={`${problem.path}-${problem.line}-${index}`}
                            className="min-w-0"
                        >
                            <ConfigProblem
                                problem={problem}
                                path={config.path}
                                href={fileLineUrl(
                                    config.repository,
                                    config.commitSha,
                                    config.path,
                                    problem.line,
                                )}
                            />
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}
