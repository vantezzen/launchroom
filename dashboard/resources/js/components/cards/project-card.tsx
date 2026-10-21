import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { MiniBars } from '@/components/charts/mini-bars';
import type { MiniBar } from '@/components/charts/mini-bars';
import { CardChip, ObjectCard } from './object-card';

export type ProjectCardProps = {
    name: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    status?: ReactNode;
    /** Mini canvas thumbnail or a logo; omitted → no stage. */
    media?: ReactNode;
    domain?: string | null;
    lastDeploy?: {
        message: string;
        author: string;
        age: ReactNode;
        avatar?: ReactNode;
    } | null;
    environments?: string[];
    /** 14 days of deploys; hidden when all zero. */
    deploys?: MiniBar[];
};

/**
 * Project card (direction §9.3): thumbnail, name + status, primary domain,
 * last deploy line, environment chips and 14-day deploy bars.
 */
export function ProjectCard({
    name,
    href,
    status,
    media,
    domain,
    lastDeploy,
    environments = [],
    deploys,
}: ProjectCardProps) {
    return (
        <ObjectCard
            title={name}
            href={href}
            media={media}
            status={status}
            chips={
                environments.length > 0
                    ? environments.map((env) => (
                          <CardChip key={env} outline>
                              {env}
                          </CardChip>
                      ))
                    : undefined
            }
        >
            {domain && (
                <p className="-mt-2 truncate type-mono text-muted-foreground">
                    {domain}
                </p>
            )}
            {lastDeploy && (
                <p className="flex min-w-0 items-center gap-2 type-small text-muted-foreground">
                    {lastDeploy.avatar}
                    <span className="min-w-0 truncate">
                        <span className="text-foreground">
                            {lastDeploy.message}
                        </span>{' '}
                        · {lastDeploy.author}
                    </span>
                    <span className="ml-auto shrink-0 tabular-nums">
                        {lastDeploy.age}
                    </span>
                </p>
            )}
            {deploys && (
                <MiniBars
                    bars={deploys}
                    label={`${name} deploys, last 14 days`}
                    className="mt-auto"
                />
            )}
        </ObjectCard>
    );
}
