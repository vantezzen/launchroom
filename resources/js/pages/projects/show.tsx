import { Head, useForm, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import PageLayout from '@/layouts/page-layout';
import { SharedData } from '@/types';
import { FormEventHandler, useEffect } from 'react';

export default function ProjectShow() {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        project_environment_id: '',
    });
    const {
        props: { currentTeam, currentProject },
    } = usePage<SharedData>();

    useEffect(() => {
        setData('project_environment_id', currentProject.environments[0].id);
    }, [currentProject]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('deployments.store'));
    };

    return (
        <AppLayout>
            <Head title={currentProject.name} />

            <PageLayout title={currentProject.name}>
                <Button disabled={processing} onClick={submit}>
                    Trigger build
                </Button>
            </PageLayout>
        </AppLayout>
    );
}
