import { Head, Link, router, usePage } from '@inertiajs/react';

import { ProjectGrid } from '@/components/projects/ProjectGrid';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import PageLayout from '@/layouts/page-layout';
import { SharedData } from '@/types';
import { Plus } from 'lucide-react';

export default function TeamShow() {
    const {
        props: { currentTeam },
    } = usePage<SharedData>();

    return (
        <AppLayout>
            <Head title={currentTeam.name} />

            <PageLayout
                title={currentTeam.name}
                actions={
                    <Link href={`/teams/${currentTeam.slug}/projects/create`}>
                        <Button>
                            <Plus />
                            New project
                        </Button>
                    </Link>
                }
            >
                <ProjectGrid
                    projects={currentTeam.projects || []}
                    onProjectClick={(project) => {
                        router.visit(`/teams/${currentTeam.slug}/projects/${project.slug}`);
                    }}
                />

                {!currentTeam.projects?.length && <EmptyState title="No projects" description="Create a project to get started." />}
            </PageLayout>
        </AppLayout>
    );
}
