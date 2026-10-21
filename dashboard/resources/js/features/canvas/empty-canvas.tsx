import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { openAddServiceWith } from '@/features/add-service/preset';
import { cn } from '@/lib/utils';
import { GROUPS } from './groups';
import type { GroupId } from './groups';

function GhostTile({
    logo,
    label,
    onClick,
}: {
    logo: string;
    label: string;
    onClick?: () => void;
}) {
    const content = (
        <>
            <Logo kind={logo} decorative className="text-muted-foreground" />
            <span className="flex-1 text-left type-label">{label}</span>
            {onClick && (
                <Plus
                    aria-hidden="true"
                    className="size-4 text-muted-foreground"
                />
            )}
        </>
    );

    return onClick ? (
        <button
            type="button"
            onClick={onClick}
            className="flex w-full items-center gap-3 rounded-[14px] border border-dashed bg-card/60 p-3 transition-colors duration-fast hover:border-border-strong hover:bg-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {content}
        </button>
    ) : (
        <div className="flex w-full items-center gap-3 rounded-[14px] border border-dashed p-3 text-muted-foreground">
            {content}
        </div>
    );
}

/**
 * Empty canvas (direction §9.16): the three group frames drawn empty with
 * ghost tiles, not a modal. One line says what goes here.
 */
export function EmptyCanvas({ canAdd }: { canAdd: boolean }) {
    const tiles: Record<GroupId, ReactNode> = {
        public: <GhostTile logo="nextjs" label="Web apps with an address" />,
        app: (
            <GhostTile
                logo="github"
                label="Add a repository"
                onClick={
                    canAdd ? () => openAddServiceWith('github') : undefined
                }
            />
        ),
        data: (
            <GhostTile
                logo="postgres"
                label="Add a database"
                onClick={
                    canAdd ? () => openAddServiceWith('database') : undefined
                }
            />
        ),
    };

    return (
        <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-6 p-6">
            <div className="pointer-events-auto grid w-full max-w-[880px] gap-4 md:grid-cols-3">
                {GROUPS.map((group) => (
                    <div
                        key={group.id}
                        className={cn(
                            'flex flex-col gap-3 rounded-[18px] border border-dashed bg-background/70 p-4 pt-3',
                        )}
                    >
                        <span className="flex items-center gap-1.5 type-small text-muted-foreground">
                            <group.icon
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {group.label}
                        </span>
                        {tiles[group.id]}
                    </div>
                ))}
            </div>
            <p className="pointer-events-auto type-body text-muted-foreground">
                {canAdd
                    ? 'Add a repository or a database to get started.'
                    : 'Nothing here yet. Adding services needs the developer role.'}
            </p>
        </div>
    );
}
