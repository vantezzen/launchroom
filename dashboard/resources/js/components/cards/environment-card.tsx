import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { MetaDivider } from '@/components/patterns/meta-line';
import { ObjectCard } from './object-card';

/** Environment card (direction §5.2): name, trigger, live revision, changes ahead/behind, services. */
export function EnvironmentCard({
    name,
    href,
    status,
    trigger,
    revision,
    ahead,
    behind,
    services,
    action,
}: {
    name: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    status?: ReactNode;
    /** "Deploys on push to main". */
    trigger?: string;
    /** Live short SHA. */
    revision?: string | null;
    ahead?: number;
    behind?: number;
    services?: number;
    /** Inner action (e.g. Promote); rendered above the card link. */
    action?: ReactNode;
}) {
    return (
        <ObjectCard
            title={name}
            href={href}
            status={status}
            footer={
                <>
                    <span>
                        {services !== undefined
                            ? `${services} ${services === 1 ? 'service' : 'services'}`
                            : ''}
                    </span>
                    {revision && <span className="font-mono">{revision}</span>}
                </>
            }
        >
            {trigger && (
                <p className="-mt-2 type-small text-muted-foreground">
                    {trigger}
                </p>
            )}
            {(ahead !== undefined || behind !== undefined) && (
                <p className="flex items-center gap-2 type-body tabular-nums">
                    {ahead ? <span>{ahead} ahead</span> : null}
                    {ahead && behind ? <MetaDivider /> : null}
                    {behind ? <span>{behind} behind</span> : null}
                    {!ahead && !behind ? (
                        <span className="text-muted-foreground">
                            Up to date
                        </span>
                    ) : null}
                </p>
            )}
            {action && <div className="relative z-10">{action}</div>}
        </ObjectCard>
    );
}
