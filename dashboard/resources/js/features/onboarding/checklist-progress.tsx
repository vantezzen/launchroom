import { Link } from '@inertiajs/react';
import { Check, ChevronRight, Globe, Search, UserPlus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageHeader } from '@/components/patterns/page';
import { DoneRing } from '@/components/steps/step-card';
import { openCommandPalette } from '@/features/command-palette/command-palette';
import { modShortcut } from '@/lib/platform';
import services from '@/routes/projects/services';
import { edit as teamSettings } from '@/routes/team';

type Progress = App.Data.Instance.OnboardingProgressData;
type Step = App.Data.Instance.OnboardingStepData;

/**
 * Get started's hero (direction §9.12, D109): the ring counts done steps
 * only, so the words and the ring agree. Three states: steps left, only
 * optional (skipped) steps left, complete.
 */
export function OnboardingHero({
    progress,
    projectPending,
    serverName,
}: {
    progress: Progress;
    projectPending: boolean;
    serverName?: string;
}) {
    const left = progress.total - progress.done - progress.skipped;

    return (
        <PageHeader
            variant="display"
            title={
                progress.complete
                    ? 'You’re all set.'
                    : progress.settled
                      ? 'Your launchroom is ready.'
                      : 'Your launchroom is up.'
            }
            description={
                progress.complete
                    ? undefined
                    : progress.settled
                      ? `${progress.done} of ${progress.total} done, ${progress.skipped} optional left.`
                      : projectPending
                        ? `${left} short ${left === 1 ? 'step' : 'steps'} until your first app is live${serverName ? ` on ${serverName}` : ''}.`
                        : left === 1
                          ? 'One more step and you’re done.'
                          : `${left} steps left. Nothing here is urgent.`
            }
            actions={
                <DoneRing
                    done={progress.done}
                    total={progress.total}
                    size={64}
                />
            }
            className="flex-row items-center justify-between"
        />
    );
}

const DONE_NAMES: Record<App.Enums.Instance.OnboardingStep, string> = {
    server: 'Server',
    github: 'GitHub',
    domain: 'address',
    project: 'first app',
    backups: 'backups',
};

/** "Server, address and first app are set up": the done steps in one row. */
export function DoneSummary({ steps }: { steps: Step[] }) {
    const names = steps
        .filter((step) => step.status === 'done')
        .map((step) => DONE_NAMES[step.id]);

    if (names.length === 0) {
        return null;
    }

    const list =
        names.length === 1
            ? names[0]
            : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;

    return (
        <li className="flex items-center gap-4 rounded-xl border bg-card px-5 py-4 shadow-card">
            <Check
                aria-hidden="true"
                className="size-5 shrink-0 text-status-ok"
            />
            <span className="type-body">
                {list.charAt(0).toUpperCase() + list.slice(1)}{' '}
                {names.length === 1 ? 'is' : 'are'} set up.
            </span>
        </li>
    );
}

type NextRow = {
    key: string;
    icon: LucideIcon;
    title: string;
    line: string;
} & ({ href: string } | { onClick: () => void });

/**
 * "What's next" (D109): a quiet card once the checklist has nothing
 * required left. The server decides what the owner may do; ⌘K is always
 * there.
 */
export function WhatsNext({
    next,
    firstApp,
}: {
    next: App.Data.Instance.OnboardingNextData;
    firstApp: App.Data.Instance.FirstAppData | null;
}) {
    const rows: NextRow[] = [
        ...(next.customDomain && firstApp
            ? [
                  {
                      key: 'domain',
                      icon: Globe,
                      title: 'Use your own domain',
                      line: `Give ${firstApp.serviceName} an address like app.example.com.`,
                      href: services.section({
                          project: firstApp.projectId,
                          environment: firstApp.environmentId,
                          service: firstApp.serviceId,
                          section: 'domains',
                      }).url,
                  },
              ]
            : []),
        ...(next.invite
            ? [
                  {
                      key: 'invite',
                      icon: UserPlus,
                      title: 'Invite a teammate',
                      line: 'Deploys, logs and alerts are shared with everyone in the team.',
                      href: teamSettings().url,
                  },
              ]
            : []),
        {
            key: 'search',
            icon: Search,
            title: `Find anything with ${modShortcut('K')}`,
            line: 'Projects, services, deployments and settings in one search.',
            onClick: () => openCommandPalette(),
        },
    ];
    const row =
        'group flex w-full items-center gap-4 px-5 py-4 text-left transition-colors duration-fast hover:bg-hover focus-visible:ring-2 focus-visible:ring-signal focus-visible:outline-none focus-visible:ring-inset';

    return (
        <section aria-labelledby="whats-next" className="flex flex-col gap-3.5">
            <h2 id="whats-next" className="type-heading">
                What’s next
            </h2>
            <ul className="divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                {rows.map((item) => {
                    const Icon = item.icon;
                    const content = (
                        <>
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border bg-recessed">
                                <Icon
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="type-body font-medium">
                                    {item.title}
                                </span>
                                <span className="type-small text-muted-foreground">
                                    {item.line}
                                </span>
                            </span>
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 shrink-0 text-faint transition-transform duration-fast group-hover:translate-x-0.5"
                            />
                        </>
                    );

                    return (
                        <li key={item.key}>
                            {'href' in item ? (
                                <Link href={item.href} className={row}>
                                    {content}
                                </Link>
                            ) : (
                                <button
                                    type="button"
                                    onClick={item.onClick}
                                    className={row}
                                >
                                    {content}
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
