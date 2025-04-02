import { useForm, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import EnvironmentLayout from '@/layouts/environment-layout';
import { SharedData } from '@/types';
import { ExternalLink, Github, Hammer } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';
import DeploymentDiagram from './components/DeploymentDiagram';

export default function ProjectShow() {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        environment_id: '',
    });
    const {
        props: { currentTeam, currentProject, currentEnvironment },
    } = usePage<SharedData>();

    useEffect(() => {
        if (!currentProject) return;
        setData('environment_id', currentProject.environments[0].id);
    }, [currentProject]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('deployments.store'));
    };

    return (
        <EnvironmentLayout
            title="Overview"
            actions={
                <>
                    <a href={`http://${currentEnvironment?.domains[0]}`} target="_blank" rel="noreferrer">
                        <Button variant="secondary">
                            <ExternalLink className="mr-2 h-4 w-4" />
                            Visit
                        </Button>
                    </a>
                    <a href={`${currentProject?.repository}/tree/${currentEnvironment?.branch}`} target="_blank" rel="noreferrer">
                        <Button variant="secondary">
                            <Github className="mr-2 h-4 w-4" />
                            Show repository
                        </Button>
                    </a>
                    <Button disabled={processing} onClick={submit}>
                        <Hammer />
                        Trigger deployment
                    </Button>
                </>
            }
        >
            <DeploymentDiagram environment={currentEnvironment!} />
        </EnvironmentLayout>
    );
}
