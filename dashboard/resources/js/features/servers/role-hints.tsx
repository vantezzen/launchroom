import { Lightbulb, TriangleAlert } from 'lucide-react';
import { LearnMore, useDocsUrl } from '@/features/settings/settings-card';
import { cn } from '@/lib/utils';

type Hint = App.Data.Servers.RoleHintData;

/** The wizard's key for a choice: "home:build,edge" (roles sorted). */
export function roleHintKey(
    machine: string | null | undefined,
    roles: App.Enums.ServerRole[],
): string {
    return `${machine ?? ''}:${[...roles].sort().join(',')}`;
}

/**
 * Contextual notes under the role cards (docs/08 §14, design-system
 * §13.9): at most two, computed server-side (App\Support\Servers\RoleHints).
 * Neutral by default; amber only for a real risk, always with the icon.
 * The region stays mounted so changes are announced.
 */
export function RoleHints({
    hints,
    className,
}: {
    hints: Hint[];
    className?: string;
}) {
    const docsUrl = useDocsUrl();
    return (
        <div aria-live="polite" className={cn(hints.length === 0 && 'sr-only')}>
            {hints.length > 0 && (
                <ul className={cn('flex flex-col gap-3', className)}>
                    {hints.map((hint) => {
                        const warning = hint.tone === 'warning';
                        const Icon = warning ? TriangleAlert : Lightbulb;

                        return (
                            <li
                                key={hint.id}
                                className="flex items-start gap-3.5 rounded-xl border bg-card px-4 py-3.5"
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'flex size-7 shrink-0 items-center justify-center rounded-lg bg-recessed',
                                        warning
                                            ? 'text-status-progress'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <Icon className="size-3.5" />
                                </span>
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <p className="type-body font-medium">
                                        {warning && (
                                            <span className="sr-only">
                                                Heads up:{' '}
                                            </span>
                                        )}
                                        {hint.title}
                                    </p>
                                    <p className="type-small text-muted-foreground">
                                        {hint.body}
                                        {hint.docs !== null && (
                                            <>
                                                {' '}
                                                <LearnMore
                                                    href={docsUrl(hint.docs)}
                                                />
                                            </>
                                        )}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
