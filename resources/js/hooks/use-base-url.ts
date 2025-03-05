import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

export default function useBaseUrl() {
    const {
        props: { currentProject, currentEnvironment, currentTeam },
    } = usePage<SharedData>();
    const baseUrl = `/teams/${currentTeam.slug}/projects/${currentProject.slug}/environments/${currentEnvironment.id}/`;
    return baseUrl;
}
