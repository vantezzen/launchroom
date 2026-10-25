import { useLayoutEffect, useRef, useState } from 'react';
import { Logo } from '@/components/logo';
import { GROUPS } from '@/features/canvas/groups';
import { cn } from '@/lib/utils';

type Preview = App.Data.Blueprints.BlueprintPreviewData;
type ServicePreview = App.Data.Blueprints.BlueprintServicePreviewData;

type Path = { key: string; d: string; type: string };

/**
 * The canvas a template or Compose file becomes, before it exists
 * (docs/17 §4): the Public · App · Data frames of the real canvas with a
 * tile per service, joined by quiet curves (dashed for a worker's link to
 * its app). Purely a picture; the notes and choices live next to it.
 */
export function BlueprintCanvas({
    preview,
    onSelect,
    selected,
    className,
}: {
    preview: Preview;
    onSelect?: (key: string) => void;
    selected?: string | null;
    className?: string;
}) {
    const container = useRef<HTMLDivElement>(null);
    const [paths, setPaths] = useState<Path[]>([]);
    const groups = GROUPS.map((group) => ({
        ...group,
        services: preview.services.filter(
            (service) => service.group === group.id,
        ),
    })).filter((group) => group.services.length > 0);

    useLayoutEffect(() => {
        const root = container.current;

        if (!root) {
            return;
        }

        const measure = () => {
            const box = root.getBoundingClientRect();
            const rect = (key: string) =>
                root
                    .querySelector(`[data-node="${CSS.escape(key)}"]`)
                    ?.getBoundingClientRect();
            const next: Path[] = [];

            for (const edge of preview.edges) {
                const from = rect(edge.from);
                const to = rect(edge.to);

                if (!from || !to) {
                    continue;
                }

                // Side by side: right edge to left edge; stacked: bottom to top.
                const sideways = Math.abs(from.left - to.left) > from.width / 2;
                const [a, b] = from.left <= to.left ? [from, to] : [to, from];
                const d = sideways
                    ? (() => {
                          const x1 = a.right - box.left;
                          const y1 = a.top + a.height / 2 - box.top;
                          const x2 = b.left - box.left;
                          const y2 = b.top + b.height / 2 - box.top;
                          const mid = (x1 + x2) / 2;

                          return `M ${x1} ${y1} C ${mid} ${y1}, ${mid} ${y2}, ${x2} ${y2}`;
                      })()
                    : (() => {
                          const [top, bottom] =
                              from.top <= to.top ? [from, to] : [to, from];
                          const x1 = top.left + 28 - box.left;
                          const y1 = top.bottom - box.top;
                          const x2 = bottom.left + 28 - box.left;
                          const y2 = bottom.top - box.top;

                          return `M ${x1} ${y1} C ${x1 - 16} ${(y1 + y2) / 2}, ${x2 - 16} ${(y1 + y2) / 2}, ${x2} ${y2}`;
                      })();

                next.push({
                    key: `${edge.from}-${edge.to}-${edge.type}`,
                    d,
                    type: edge.type,
                });
            }

            setPaths(next);
        };

        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(root);

        return () => observer.disconnect();
    }, [preview]);

    return (
        <div
            ref={container}
            role="img"
            aria-label={`${preview.services.length} services: ${preview.services.map((service) => service.name).join(', ')}`}
            className={cn('relative', className)}
        >
            <svg
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 size-full overflow-visible"
            >
                {paths.map((path) => (
                    <path
                        key={path.key}
                        d={path.d}
                        fill="none"
                        stroke="var(--border-strong)"
                        strokeWidth={1.25}
                        strokeDasharray={
                            path.type === 'process' ? '3 4' : undefined
                        }
                    />
                ))}
            </svg>
            <div
                className={cn(
                    'relative grid gap-6',
                    groups.length >= 3
                        ? 'sm:grid-cols-3'
                        : groups.length === 2
                          ? 'sm:grid-cols-2'
                          : 'grid-cols-1',
                )}
            >
                {groups.map((group) => (
                    <div
                        key={group.id}
                        className="flex min-w-0 flex-col gap-3 rounded-[18px] border bg-foreground/[0.025] p-3 pt-2.5"
                    >
                        <span className="flex items-center gap-1.5 px-1 type-small text-muted-foreground">
                            <group.icon
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {group.label}
                            <span className="rounded-full bg-(--hover) px-1.5 tabular-nums">
                                {group.services.length}
                            </span>
                        </span>
                        {group.services.map((service) => (
                            <ServiceTile
                                key={service.key}
                                service={service}
                                selected={selected === service.key}
                                onSelect={onSelect}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

function ServiceTile({
    service,
    selected,
    onSelect,
}: {
    service: ServicePreview;
    selected: boolean;
    onSelect?: (key: string) => void;
}) {
    const refused = service.notes.filter(
        (note) => note.kind === 'refused',
    ).length;
    const body = (
        <>
            <Logo
                kind={service.logo}
                name={service.name}
                size={32}
                decorative
            />
            <span className="flex min-w-0 flex-col">
                <span className="truncate type-label">{service.name}</span>
                <span className="truncate type-small text-muted-foreground">
                    {service.subtitle}
                </span>
            </span>
            {refused > 0 && (
                <span
                    className="ml-auto shrink-0 type-mono-small text-status-error"
                    title={`${refused} refused`}
                >
                    ✕ {refused}
                </span>
            )}
        </>
    );
    const classes = cn(
        'relative flex min-w-0 items-center gap-3 rounded-xl border bg-card p-3 text-left shadow-card',
        selected && 'border-foreground shadow-[0_0_0_0.5px_var(--foreground)]',
    );

    return onSelect ? (
        <button
            type="button"
            data-node={service.key}
            onClick={() => onSelect(service.key)}
            className={cn(
                classes,
                'transition-colors duration-fast hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
            )}
        >
            {body}
        </button>
    ) : (
        <div data-node={service.key} className={classes}>
            {body}
        </div>
    );
}

/** "2 CPUs, 1.5 GB, 2 volumes" for a blueprint. */
export function resourcesLine(preview: Preview): string {
    const memory =
        preview.memoryMb >= 1024
            ? `${Number((preview.memoryMb / 1024).toFixed(1))} GB`
            : `${preview.memoryMb} MB`;
    const cpus = `${Number(preview.cpus.toFixed(2))} ${preview.cpus === 1 ? 'CPU' : 'CPUs'}`;
    const volumes =
        preview.volumes === 0
            ? null
            : `${preview.volumes} ${preview.volumes === 1 ? 'volume' : 'volumes'}`;

    return [cpus, memory, volumes].filter(Boolean).join(', ');
}
