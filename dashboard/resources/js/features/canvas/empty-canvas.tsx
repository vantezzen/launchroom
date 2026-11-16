import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/logo';
import { choices } from '@/features/add-service/add-service-sheet';
import { cn } from '@/lib/utils';
import { GROUPS } from './groups';
import type { GroupId } from './groups';

function GhostTile({
    logo,
    label,
    description,
    onClick,
}: {
    logo: string;
    label: string;
    description?: string;
    onClick?: () => void;
}) {
    const content = (
        <>
            <Logo kind={logo} decorative className="text-muted-foreground" />
            <span className="flex min-w-0 flex-1 flex-col text-left">
                <span className="type-label">{label}</span>
                {description && (
                    <span className="type-small text-muted-foreground">
                        {description}
                    </span>
                )}
            </span>
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
        <div className="flex w-full items-start gap-3 rounded-[14px] border border-dashed p-3 text-muted-foreground">
            {content}
        </div>
    );
}

/**
 * Empty canvas (direction §9.16): the three group frames drawn empty with
 * ghost tiles, not a modal. One line says what goes here. Without
 * `onAdd` (no permission) the tiles only describe.
 */
export function EmptyCanvas({
    onAdd,
}: {
    onAdd?: (choice: 'github' | 'database') => void;
}) {
    // The add sheet's own lines, so both say the same.
    const describe = (id: 'github' | 'database') =>
        choices.find((choice) => choice.id === id)?.description;
    const tiles: Record<GroupId, ReactNode> = {
        public: (
            <GhostTile
                logo="nextjs"
                label="Web apps with an address"
                description="Apps with a domain show up here."
            />
        ),
        app: (
            <GhostTile
                logo="github"
                label="Add a repository"
                description={describe('github')}
                onClick={onAdd ? () => onAdd('github') : undefined}
            />
        ),
        data: (
            <GhostTile
                logo="postgres"
                label="Add a database"
                description={describe('database')}
                onClick={onAdd ? () => onAdd('database') : undefined}
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
                {onAdd
                    ? 'Add a repository or a database to get started.'
                    : 'Nothing here yet. Adding services needs the developer role.'}
            </p>
        </div>
    );
}
