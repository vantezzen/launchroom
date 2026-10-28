import Image from 'next/image';
import screenshots from '@/lib/screenshots.json';
import { cn } from '@/lib/cn';

type Theme = 'light' | 'dark';
/** One stored image per theme: public/screenshots/<id>-<theme>.webp. */
type Variant = { width: number; height: number; blur: string };
/** `scale`: the device pixel ratio of the capture (2: 1440 CSS px stored as 2880 px). */
type Entry = { alt: string; caption: string | null; scale?: number } & Partial<Record<Theme, Variant>>;

const manifest = screenshots as Record<string, Entry>;

export const screenshotIds = Object.keys(manifest);

/**
 * Multiplies the slot width of every `sizes` entry by `factor`, keeping the
 * media conditions: a cut-out shows the image larger than its frame, so the
 * browser has to pick a correspondingly wider source to stay sharp.
 */
function scaleSizes(sizes: string, factor: number): string {
    if (factor <= 1) return sizes;
    return sizes
        .split(',')
        .map((part) =>
            part.replace(
                /(\d+(?:\.\d+)?)(px|vw)\s*$/,
                (_, n: string, unit: string) => `${Math.ceil(Number(n) * factor)}${unit}`,
            ),
        )
        .join(',');
}

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
    region,
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
    /**
     * Show only this rectangle of the screenshot, in CSS pixels of the captured
     * page (1440 wide for a full-width capture), whatever the capture's device
     * pixel ratio. The frame takes the rectangle's aspect.
     */
    region?: { x: number; y: number; width: number; height: number };
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
    // The stored image in CSS pixels of the captured page.
    const cssWidth = base.width / (entry.scale ?? 1);
    const cssHeight = base.height / (entry.scale ?? 1);
    const regionStyle = region
        ? {
              width: `${(cssWidth / region.width) * 100}%`,
              left: `${(-region.x / region.width) * 100}%`,
              top: `${(-region.y / region.height) * 100}%`,
          }
        : undefined;
    const shownCaption = caption === false ? null : (caption ?? entry.caption);
    // How much wider than its frame the image is drawn.
    let enlarge = 1;
    if (region) {
        enlarge = cssWidth / region.width;
    } else if (crop) {
        const [w, h] = crop.aspect.split('/').map(Number);
        const frameAspect = w && h ? w / h : cssWidth / cssHeight;
        enlarge = (crop.zoom ?? 1) * Math.max(1, cssWidth / cssHeight / frameAspect);
    }
    const imageSizes = scaleSizes(sizes, enlarge);

    const image = (theme: Theme, v: Variant, only?: string) => (
        <Image
            key={theme}
            src={`/screenshots/${id}-${theme}.webp`}
            width={v.width}
            height={v.height}
            alt={text}
            sizes={imageSizes}
            quality={85}
            preload={preload}
            placeholder="blur"
            blurDataURL={v.blur}
            className={cn(
                crop
                    ? 'absolute inset-0 h-full w-full object-cover'
                    : region
                      ? 'absolute h-auto max-w-none'
                      : 'block h-auto w-full',
                only,
            )}
            style={
                regionStyle ??
                (crop
                    ? {
                          objectPosition: `${crop.x ?? 50}% ${crop.y ?? 0}%`,
                          transform: `scale(${crop.zoom ?? 1})`,
                          transformOrigin: `${crop.x ?? 50}% ${crop.y ?? 0}%`,
                      }
                    : undefined)
            }
        />
    );

    return (
        <figure className={cn('lr-figure not-prose m-0', className)}>
            <div
                className={cn(
                    'overflow-hidden bg-recessed',
                    !frameless && 'rounded-card border border-border shadow-card',
                    (crop || region) && 'relative',
                )}
                style={
                    crop
                        ? { aspectRatio: crop.aspect }
                        : region
                          ? { aspectRatio: `${region.width} / ${region.height}` }
                          : undefined
                }
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
