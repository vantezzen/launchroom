import { useForm, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import EnvironmentLayout from '@/layouts/environment-layout';
import { SharedData } from '@/types';
import { Hammer } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';
import DeploymentDiagram from './components/DeploymentDiagram';

export default function ProjectShow() {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        project_environment_id: '',
    });
    const {
        props: { currentTeam, currentProject, currentEnvironment },
    } = usePage<SharedData>();

    useEffect(() => {
        if (!currentProject) return;
        setData('project_environment_id', currentProject.environments[0].id);
    }, [currentProject]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('deployments.store'));
    };

    return (
        <EnvironmentLayout
            title="Overview"
            actions={
                <Button disabled={processing} onClick={submit}>
                    <Hammer />
                    Trigger deployment
                </Button>
            }
        >
            <DeploymentDiagram environment={currentEnvironment!} />
        </EnvironmentLayout>
    );
}
