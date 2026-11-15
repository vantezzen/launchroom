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
