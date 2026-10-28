import { ChartColumn, Database, Rocket, ServerCog, ShieldCheck } from 'lucide-react';
import type { featurePages } from '@/lib/features';

const icons = {
    rocket: Rocket,
    server: ServerCog,
    database: Database,
    chart: ChartColumn,
    shield: ShieldCheck,
} as const;

export function FeatureIcon({ name, className }: { name: (typeof featurePages)[number]['icon']; className?: string }) {
    const Icon = icons[name];
    return <Icon className={className} aria-hidden="true" />;
}
