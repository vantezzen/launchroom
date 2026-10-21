import Image from 'next/image';
import screenshots from '@/lib/screenshots.json';
import { cn } from '@/lib/cn';

type Theme = 'light' | 'dark';
/** One stored image per theme: public/screenshots/<id>-<theme>.webp. */
type Variant = { width: number; height: number; blur: string };
type Entry = { alt: string; caption: string | null } & Partial<Record<Theme, Variant>>;

const manifest = screenshots as Record<string, Entry>;

export const screenshotIds = Object.keys(manifest);

/**
 * A real dashboard screenshot from lib/screenshots.json (scripts/import-screenshots.mjs).
 * One full-size source per theme; next/image resizes it on request. Light and
 * dark captures switch with the site theme by CSS only (no flash); a
 * screenshot with one theme shows that one in both.
 */
export function Screenshot({
    id,
    caption,
    alt,
    preload = false,
    sizes = '(min-width: 1024px) 800px, 100vw',
    className,
    frameless = false,
    crop,
}: {
    id: string;
    /** Overrides the manifest caption; `false` hides it. */
    caption?: string | false;
    alt?: string;
    /** Preload above-the-fold images (the hero). */
    preload?: boolean;
    sizes?: string;
    className?: string;
    /** No border/shadow (when the parent already frames it). */
    frameless?: boolean;
    /**
     * Show only part of the screenshot: the frame gets `aspect` (CSS aspect-ratio)
     * and the image is enlarged `zoom` times around `x`/`y` (percent of the image).
     */
    crop?: { aspect: string; zoom?: number; x?: number; y?: number };
}) {
    const entry = manifest[id];
    if (!entry) {
        throw new Error(`Unknown screenshot "${id}". Known: ${screenshotIds.join(', ')}`);
    }
    const { light, dark } = entry;
    const base = light ?? dark;
    if (!base) {
        throw new Error(`Screenshot "${id}" has no image`);
    }
    const text = alt ?? entry.alt;
    const shownCaption = caption === false ? null : (caption ?? entry.caption);

    const image = (theme: Theme, v: Variant, only?: string) => (
        <Image
            key={theme}
            src={`/screenshots/${id}-${theme}.webp`}
            width={v.width}
            height={v.height}
            alt={text}
            sizes={sizes}
            quality={85}
            preload={preload}
            placeholder="blur"
            blurDataURL={v.blur}
            className={cn(crop ? 'absolute inset-0 h-full w-full object-cover' : 'block h-auto w-full', only)}
            style={
                crop
                    ? {
                          objectPosition: `${crop.x ?? 50}% ${crop.y ?? 0}%`,
                          transform: `scale(${crop.zoom ?? 1})`,
                          transformOrigin: `${crop.x ?? 50}% ${crop.y ?? 0}%`,
                      }
                    : undefined
            }
        />
    );

    return (
        <figure className={cn('lr-figure not-prose m-0', className)}>
            <div
                className={cn(
                    'overflow-hidden bg-recessed',
                    !frameless && 'rounded-card border border-border shadow-card',
                    crop && 'relative',
                )}
                style={crop ? { aspectRatio: crop.aspect } : undefined}
            >
                {light && dark ? (
                    <>
                        {image('light', light, 'lr-only-light')}
                        {image('dark', dark, 'lr-only-dark')}
                    </>
                ) : (
                    image(light ? 'light' : 'dark', base)
                )}
            </div>
            {shownCaption ? (
                <figcaption className="mt-3 text-center type-small text-muted-foreground">{shownCaption}</figcaption>
            ) : null}
        </figure>
    );
}
