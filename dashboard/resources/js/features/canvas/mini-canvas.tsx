import type { CSSProperties } from 'react';
import { Logo } from '@/components/logo';
import { cn } from '@/lib/utils';

type Thumbnail = App.Data.Canvas.CanvasThumbnailData;

/**
 * A project's production canvas in miniature (direction §9.3): logo tiles at
 * their canvas positions, joined by quiet curves. Purely a picture (the
 * card around it is the link); the logos stay monochrome.
 */
export function MiniCanvas({
    canvas,
    logos = [],
    className,
}: {
    canvas: Thumbnail | null;
    /** Fallback when no layout exists yet. */
    logos?: string[];
    className?: string;
}) {
    if (!canvas || canvas.nodes.length === 0) {
        if (logos.length === 0) {
            return (
                <span className="type-small text-muted-foreground">
                    No services yet
                </span>
            );
        }

        return (
            <span aria-hidden="true" className="flex gap-2">
                {logos.slice(0, 5).map((logo, index) => (
                    <Logo key={`${logo}-${index}`} kind={logo} decorative />
                ))}
            </span>
        );
    }

    const size = canvas.nodes.length > 8 ? 20 : 32;
    const half = size / 2;
    const labelled = canvas.nodes.length <= 5;
    const byId = new Map(canvas.nodes.map((node) => [node.id, node]));
    // Wide layouts fill the width, tall ones the height (container units).
    const ratio = Math.min(Math.max(canvas.aspectRatio || 1, 0.4), 6);
    const box: CSSProperties = {
        width: `min(100cqw, calc(100cqh * ${ratio}))`,
        height: `min(100cqh, calc(100cqw / ${ratio}))`,
    };

    return (
        <div
            aria-hidden="true"
            className={cn(
                '[container-type:size] absolute inset-x-10 inset-y-8 flex items-center justify-center',
                className,
            )}
        >
            <div className="relative" style={box}>
                <svg
                    className="absolute inset-0 size-full overflow-visible"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                >
                    {canvas.edges.map((edge) => {
                        const from = byId.get(edge.source);
                        const to = byId.get(edge.target);

                        if (!from || !to) {
                            return null;
                        }

                        const [x1, y1, x2, y2] = [
                            from.x * 100,
                            from.y * 100,
                            to.x * 100,
                            to.y * 100,
                        ];
                        const mid = (x1 + x2) / 2;

                        return (
                            <path
                                key={`${edge.source}-${edge.target}`}
                                d={`M ${x1} ${y1} C ${mid} ${y1}, ${mid} ${y2}, ${x2} ${y2}`}
                                fill="none"
                                stroke="var(--border-strong)"
                                strokeWidth={1.25}
                                strokeDasharray={
                                    edge.type === 'process' ? '2 3' : undefined
                                }
                                vectorEffect="non-scaling-stroke"
                            />
                        );
                    })}
                </svg>
                {canvas.nodes.map((node) => (
                    <span
                        key={node.id}
                        className="absolute flex flex-col items-center gap-1"
                        style={{
                            left: `calc(${node.x * 100}% - ${half}px)`,
                            top: `calc(${node.y * 100}% - ${half}px)`,
                            width: size,
                        }}
                    >
                        <Logo
                            kind={node.logo}
                            size={size}
                            decorative
                            className="shadow-card"
                        />
                        {/* Names only while they fit: many image services
                            share one logo, the name tells them apart. */}
                        {labelled && node.name && (
                            <span className="max-w-20 truncate text-[11px] leading-none text-muted-foreground">
                                {node.name}
                            </span>
                        )}
                    </span>
                ))}
            </div>
        </div>
    );
}
