import Image from 'next/image';
import manifest from '@/public/devices/manifest.json';
import { cn } from '@/lib/cn';

/**
 * A device render with its status LED, as in the dashboard's DeviceImage
 * (docs/design-system.md §7): one 16:10 canvas per render with a shared
 * baseline, so any two devices line up. Decorative: names and status are
 * always written next to it.
 */
export type DeviceRender = 'server' | 'tower' | 'mac' | 'nuc' | 'nas' | 'raspberry-pi' | 'laptop';
export type DeviceState = 'online' | 'busy' | 'off';

const LED: Record<DeviceState, string> = {
    online: 'var(--device-led-ok)',
    busy: 'var(--device-led-busy)',
    off: 'var(--device-led-off)',
};

export function Device({
    render,
    state = 'online',
    width,
    className,
    ledClassName,
    sizes,
    fluid = false,
}: {
    render: DeviceRender;
    state?: DeviceState;
    /** Rendered width in px; height follows the 16:10 canvas. */
    width: number;
    className?: string;
    /** Extra classes for the LED (used by the animated moments). */
    ledClassName?: string;
    sizes?: string;
    /** Fill the parent's width, up to `width`. */
    fluid?: boolean;
}) {
    const led = manifest.devices[render].led;
    const ledSize = Math.max(3, Math.round(width / 70));
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-block shrink-0 select-none',
                !className?.includes('absolute') && 'relative',
                className,
            )}
            style={
                fluid ? { width: '100%', maxWidth: width, aspectRatio: '16 / 10' } : { width, aspectRatio: '16 / 10' }
            }
        >
            <Image
                src={`/devices/${render}.webp`}
                width={320}
                height={200}
                alt=""
                sizes={sizes ?? `${width}px`}
                className="block h-full w-full"
                draggable={false}
            />
            <span
                className={cn('absolute rounded-full', ledClassName)}
                style={{
                    left: `${led[0]}%`,
                    top: `${led[1]}%`,
                    width: ledSize,
                    height: ledSize,
                    transform: 'translate(-50%, -50%)',
                    backgroundColor: LED[state],
                    boxShadow:
                        state === 'off'
                            ? undefined
                            : `0 0 0 ${Math.max(1, ledSize * 0.75)}px color-mix(in srgb, ${LED[state]} 18%, transparent)`,
                }}
            />
        </span>
    );
}
