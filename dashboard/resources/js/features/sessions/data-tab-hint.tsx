import { Link } from '@inertiajs/react';
import { Database } from 'lucide-react';
import services from '@/routes/projects/services';
import type { ServiceRef } from './api';

/**
 * The Console of a database points at its Data tab: browsing tables and
 * running queries there needs no shell.
 */
export function DataTabHint(ref: ServiceRef) {
    return (
        <p className="flex items-center gap-2 type-small text-muted-foreground">
            <Database aria-hidden="true" className="size-3.5 shrink-0" />
            <span>
                To look at data or run queries, use the{' '}
                <Link
                    href={services.show({ ...ref, tab: 'data' })}
                    only={['panel']}
                    preserveState
                    preserveScroll
                    className="font-medium text-foreground underline-offset-2 hover:underline focus-visible:underline focus-visible:outline-none"
                >
                    Data tab
                </Link>
                .
            </span>
        </p>
    );
}
