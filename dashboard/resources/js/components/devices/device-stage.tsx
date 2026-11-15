import type { ReactNode } from 'react';
import { DEVICE_ART } from '@/components/devices/device-art.generated';
import { DEVICE_RENDER, DeviceImage } from '@/components/devices/device-image';
import type {
    DeviceKind,
    DeviceStatus,
} from '@/components/devices/device-image';
import { cn } from '@/lib/utils';

const DEVICE_SIZE = 168;

/** px from the stage's bottom to just above the device body (art metadata). */
function unitsBottom(kind: DeviceKind, size = DEVICE_SIZE): number {
    const art = DEVICE_ART.devices[DEVICE_RENDER[kind]];
    const [, canvasHeight] = DEVICE_ART.canvas;
    const height = (size * 10) / 16;
    const top = (art.body[1] / canvasHeight) * height;

    return 4 + height - top + 6;
}

/**
 * A device on the recessed stage with things standing on its top edge
 * (replicas, a volume): the art's body box says where that edge is.
 */
export function DeviceStage({
    device,
    status,
    dimmed = false,
    size = DEVICE_SIZE,
    className,
    children,
}: {
    device: DeviceKind;
    status: DeviceStatus;
    dimmed?: boolean;
    size?: number;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'relative flex h-[150px] items-end justify-center bg-recessed pb-1',
                className,
            )}
        >
            <div
                className={cn(
                    'transition-[filter,opacity] duration-base',
                    dimmed && 'opacity-45 grayscale',
                )}
            >
                <DeviceImage device={device} status={status} size={size} />
            </div>
            {children && (
                <div
                    className="absolute inset-x-0 z-10 flex justify-center px-4"
                    style={{ bottom: unitsBottom(device, size) }}
                >
                    {children}
                </div>
            )}
        </div>
    );
}
