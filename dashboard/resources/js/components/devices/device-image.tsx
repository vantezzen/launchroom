import { cn } from '@/lib/utils';
import { DEVICE_ART } from './device-art.generated';

/** Device kinds from the classifier (docs/design/direction.md §7.1, §7.3). */
export type DeviceKind =
    | 'cloud'
    | 'dedicated'
    | 'tower'
    | 'minipc'
    | 'compact'
    | 'sbc'
    | 'nas'
    | 'laptop'
    | 'vm'
    | 'unknown';

export type DeviceStatus =
    | 'online'
    | 'busy'
    | 'unreachable'
    | 'offline'
    | 'pending';

type Render = keyof typeof DEVICE_ART.devices;

/**
 * Kind → maintainer render (docs/design-system.md §7). Kinds without their
 * own render use the closest one until more renders exist; `unknown` is the
 * server as a flat neutral silhouette.
 */
export const DEVICE_RENDER: Record<DeviceKind, Render> = {
    cloud: 'server',
    dedicated: 'server',
    vm: 'server',
    unknown: 'server',
    compact: 'mac',
    laptop: 'laptop',
    minipc: 'nuc',
    tower: 'tower',
    nas: 'nas',
    sbc: 'raspberry-pi',
};

export const DEVICE_LABEL: Record<DeviceKind, string> = {
    cloud: 'Cloud server',
    dedicated: 'Dedicated server',
    tower: 'Tower PC',
    minipc: 'Mini PC',
    compact: 'Compact desktop',
    sbc: 'Single-board computer',
    nas: 'NAS',
    laptop: 'Laptop',
    vm: 'Virtual machine',
    unknown: 'Server',
};

const LED: Record<DeviceStatus, string | null> = {
    online: 'var(--device-led-ok)',
    busy: 'var(--device-led-busy)',
    unreachable: 'var(--device-led-error)',
    offline: 'var(--device-led-off)',
    pending: null,
};

const [CANVAS_W, CANVAS_H] = DEVICE_ART.canvas;

/**
 * A device render (design-system §7): transparent cut-out on a fixed 16:10
 * canvas with a shared baseline and contact shadow, so any two devices line
 * up. Status is applied here, not in the art: a status LED overlay (green,
 * amber, red; grey and no halo when off), desaturated when unreachable,
 * greyscale at 55 % when offline, LED-less at 55 % while pending.
 *
 * `size` is the rendered width in px (64 lists, 120 tiles, 200 card stage,
 * 300 hero); height follows the canvas ratio. Decorative by default
 * (`alt=""`) because the name and status are always next to it.
 */
export function DeviceImage({
    device,
    status = 'online',
    size = 200,
    alt = '',
    className,
}: {
    device: DeviceKind;
    status?: DeviceStatus;
    size?: number;
    alt?: string;
    className?: string;
}) {
    const render = DEVICE_RENDER[device];
    const art = DEVICE_ART.devices[render];
    const silhouette = device === 'unknown';
    const led = silhouette ? null : LED[status];
    const ledPosition = 'led' in art ? art.led : null;
    const ledSize = Math.max(2, Math.round(size / 70));
    const src = `/images/devices/${render}`;

    return (
        <span
            className={cn(
                'relative inline-block shrink-0 select-none',
                status === 'unreachable' && 'saturate-[.6]',
                status === 'offline' && 'opacity-55 grayscale',
                status === 'pending' && 'opacity-55',
                className,
            )}
            style={{ width: size, height: (size * CANVAS_H) / CANVAS_W }}
            role={alt ? 'img' : undefined}
            aria-label={alt || undefined}
            aria-hidden={alt ? undefined : true}
        >
            <picture>
                <source
                    type="image/webp"
                    srcSet={`${src}@1x.webp 1x, ${src}@2x.webp 2x`}
                />
                <img
                    src={`${src}@2x.png`}
                    alt=""
                    width={size}
                    height={(size * CANVAS_H) / CANVAS_W}
                    loading="lazy"
                    decoding="async"
                    draggable={false}
                    className={cn(
                        'block h-full w-full',
                        silhouette &&
                            'opacity-[.14] brightness-0 dark:opacity-[.32] dark:invert',
                    )}
                />
            </picture>
            {led && ledPosition && (
                <span
                    aria-hidden="true"
                    className="absolute rounded-full motion-safe:transition-colors motion-safe:duration-[180ms]"
                    style={{
                        left: `${ledPosition[0]}%`,
                        top: `${ledPosition[1]}%`,
                        width: ledSize,
                        height: ledSize,
                        transform: 'translate(-50%, -50%)',
                        backgroundColor: led,
                        boxShadow:
                            status === 'offline'
                                ? undefined
                                : `0 0 0 ${Math.max(1, ledSize * 0.75)}px color-mix(in srgb, ${led} 18%, transparent)`,
                    }}
                />
            )}
        </span>
    );
}

/** Alias of DeviceImage. */
export const DeviceIllustration = DeviceImage;
