import type { ReactNode } from 'react';
import { StepPills } from '@/components/steps/step-pills';
import type { StepStatus } from '@/components/steps/step-icon';
import { cn } from '@/lib/utils';

/**
 * Active deployment (direction §5.2): status pill, commit
 * message as the title, a meta line (sha, branch, author, age), the step
 * pills strip and actions. Not a link card: its actions are the point.
 */
export function DeploymentCard({
    status,
    title,
    meta,
    steps,
    actions,
    avatar,
    className,
}: {
    status: ReactNode;
    title: string;
    /** Commit author avatar before the title (CommitAvatar). */
    avatar?: ReactNode;
    /** "3b7d5e1 | main | jonas | 2m ago" in a MetaLine. */
    meta: ReactNode;
    steps?: { id: string; name: string; status: StepStatus }[];
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <article
            className={cn(
                'flex flex-col gap-3 rounded-[14px] border bg-card p-5 shadow-[0_1px_2px_rgb(0_0_0/0.04)] dark:shadow-none',
                className,
            )}
        >
            <div className="flex items-center gap-3">
                {status}
                {actions && (
                    <div className="ml-auto flex items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
            <div className="flex min-w-0 items-center gap-3">
                {avatar}
                <div className="flex min-w-0 flex-col gap-1">
                    <h3 className="truncate type-heading">{title}</h3>
                    <p className="truncate type-small text-muted-foreground">
                        {meta}
                    </p>
                </div>
            </div>
            {steps && steps.length > 0 && <StepPills steps={steps} />}
        </article>
    );
}
