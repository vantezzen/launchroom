import { ArrowRight, Check, Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type SetupStepState = 'done' | 'current' | 'upcoming';

const stateLabel: Record<SetupStepState, string> = {
    done: 'Done',
    current: 'Next step',
    upcoming: 'After step 1',
};

function StateChip({ state }: { state: SetupStepState }) {
    const Icon =
        state === 'done' ? Check : state === 'current' ? ArrowRight : Clock;

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 type-small',
                state === 'current'
                    ? 'border-foreground text-foreground'
                    : 'text-muted-foreground',
            )}
        >
            <Icon aria-hidden="true" className="size-3" />
            {stateLabel[state]}
        </span>
    );
}

function SetupStep({
    number,
    state,
    title,
    detail,
    children,
}: {
    number: number;
    state: SetupStepState;
    title: string;
    detail: ReactNode;
    children?: ReactNode;
}) {
    return (
        <li
            aria-current={state === 'current' ? 'step' : undefined}
            aria-disabled={state === 'upcoming' ? true : undefined}
            className="flex gap-4 px-4 py-4 sm:px-5"
        >
            <span
                aria-hidden="true"
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-full type-label tabular-nums',
                    state === 'done' && 'bg-foreground text-background',
                    state === 'current' &&
                        'border border-foreground text-foreground',
                    state === 'upcoming' && 'border text-faint',
                )}
            >
                {state === 'done' ? <Check className="size-3.5" /> : number}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-3">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span
                        className={cn(
                            'type-body font-medium',
                            state === 'upcoming' && 'text-muted-foreground',
                        )}
                    >
                        {title}
                    </span>
                    <StateChip state={state} />
                </div>
                <p className="-mt-2 type-small text-muted-foreground">
                    {detail}
                </p>
                {children}
            </div>
        </li>
    );
}

/**
 * Connecting GitHub takes two round trips (docs/08 §3.1, docs/19): GitHub
 * creates the app from launchroom's manifest, then the app is installed on
 * an account or organization. Both steps stay visible, so returning from
 * the first one reads as progress. `createAction` is the form, redirect or
 * link that starts step 1; step 2's button is disabled until the app exists.
 */
export function GitHubSetupSteps({
    appName,
    installUrl,
    installed = false,
    createAction,
    canManage = true,
}: {
    appName: string | null;
    installUrl: string | null;
    installed?: boolean;
    createAction?: ReactNode;
    canManage?: boolean;
}) {
    const created = appName !== null;
    const create: SetupStepState = created ? 'done' : 'current';
    const install: SetupStepState = installed
        ? 'done'
        : created
          ? 'current'
          : 'upcoming';

    return (
        <ol
            aria-label="Connect GitHub in two steps"
            className="divide-y overflow-hidden rounded-xl border bg-card"
        >
            <SetupStep
                number={1}
                state={create}
                title="Create the GitHub App"
                detail={
                    created ? (
                        <>
                            Created{' '}
                            <span className="type-mono-small">{appName}</span>{' '}
                            on GitHub.
                        </>
                    ) : (
                        'GitHub creates an app with the permissions launchroom needs, then sends you back here.'
                    )
                }
            >
                {create === 'current' && createAction}
            </SetupStep>
            <SetupStep
                number={2}
                state={install}
                title="Install it on your account or organization"
                detail={
                    installed
                        ? 'Installed. Add repositories on GitHub at any time.'
                        : 'Pick the repositories launchroom may build and deploy.'
                }
            >
                {install !== 'done' && canManage && (
                    <div>
                        {install === 'current' && installUrl ? (
                            <Button asChild size="lg">
                                <a href={installUrl}>
                                    <Logo kind="github" size={20} decorative />
                                    Install on GitHub
                                </a>
                            </Button>
                        ) : (
                            <Button size="lg" variant="outline" disabled>
                                <Logo kind="github" size={20} decorative />
                                Install on GitHub
                            </Button>
                        )}
                    </div>
                )}
            </SetupStep>
        </ol>
    );
}
