import { Cpu, HardDrive, MapPin, MemoryStick } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { MetaLine } from '@/components/patterns/meta-line';
import { specItems } from '@/features/servers/format';
import type { SpecPart } from '@/features/servers/format';
import { cn } from '@/lib/utils';

const icons: Record<SpecPart['key'], LucideIcon> = {
    cpu: Cpu,
    memory: MemoryStick,
    disk: HardDrive,
};

function Item({
    icon: Icon,
    children,
}: {
    icon?: LucideIcon;
    children: string;
}) {
    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            {Icon && (
                <Icon
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-muted-foreground"
                />
            )}
            <span className="truncate">{children}</span>
        </span>
    );
}

/**
 * A server's spec line (docs/08 §14): the place with a pin first, then
 * cores, memory and disk, each with an icon and its unit word, and the
 * architecture. Card footer and hero.
 */
export function SpecLine({
    specs,
    place = null,
    arch = null,
    className,
}: {
    specs: App.Data.Servers.ServerSpecsData | null | undefined;
    place?: string | null;
    arch?: string | null;
    className?: string;
}) {
    const items = specItems(specs);

    if (items.length === 0 && !place && !arch) {
        return null;
    }

    return (
        <MetaLine className={cn('tabular-nums', className)}>
            {place && <Item icon={MapPin}>{place}</Item>}
            {items.map((item) => (
                <Item key={item.key} icon={icons[item.key]}>
                    {item.text}
                </Item>
            ))}
            {arch && <Item>{arch}</Item>}
        </MetaLine>
    );
}
