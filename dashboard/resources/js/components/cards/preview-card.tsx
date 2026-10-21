import type { InertiaLinkProps } from '@inertiajs/react';
import { GitBranch, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { ObjectCard } from './object-card';

export type PreviewCardProps = {
    title: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    status?: ReactNode;
    url?: string | null;
    branch: string;
    base: string;
    author?: string;
    protectedPreview?: boolean;
    /** "Expires in 3 days". */
    expiry?: ReactNode;
    /** Screenshot later; a URL card for now. */
    media?: ReactNode;
};

/** URL card placeholder until preview screenshots exist (maintainer decision §5 #6). */
export function UrlCard({ url }: { url: string }) {
    return (
        <div className="w-[78%] overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04)]">
            <div className="flex items-center gap-1.5 border-b px-3 py-2">
                <span className="size-1.5 rounded-full bg-(--border-strong)" />
                <span className="size-1.5 rounded-full bg-(--border-strong)" />
                <span className="size-1.5 rounded-full bg-(--border-strong)" />
                <span className="ml-2 truncate type-mono-small text-muted-foreground">
                    {url}
                </span>
            </div>
            <div className="flex flex-col gap-2 p-3">
                <span className="h-2 w-1/2 rounded-full bg-(--hover)" />
                <span className="h-2 w-3/4 rounded-full bg-(--hover)" />
            </div>
        </div>
    );
}

/** Preview card (direction §9.11): URL card, PR title, branch → base, author, protection, expiry. */
export function PreviewCard({
    title,
    href,
    status,
    url,
    branch,
    base,
    author,
    protectedPreview,
    expiry,
    media,
}: PreviewCardProps) {
    return (
        <ObjectCard
            title={title}
            href={href}
            status={status}
            media={media ?? (url ? <UrlCard url={url} /> : undefined)}
            footer={
                <>
                    <span className="inline-flex min-w-0 items-center gap-1.5 truncate">
                        <GitBranch
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                        <span className="truncate font-mono">{branch}</span> →{' '}
                        <span className="font-mono">{base}</span>
                    </span>
                    <span className="inline-flex shrink-0 items-center gap-1.5">
                        {protectedPreview && (
                            <Lock aria-label="Protected" className="size-3.5" />
                        )}
                        {expiry}
                    </span>
                </>
            }
        >
            {author && (
                <p className="-mt-2 type-small text-muted-foreground">
                    Opened by {author}
                </p>
            )}
        </ObjectCard>
    );
}
