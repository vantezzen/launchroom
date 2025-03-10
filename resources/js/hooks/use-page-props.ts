import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export function usePageProps() {
    const { props, url } = usePage<SharedData>();

    return {
        ...props,
        url,
    };
}
