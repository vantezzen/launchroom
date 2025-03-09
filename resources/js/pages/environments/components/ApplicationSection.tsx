import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Environment, SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { ChevronRight, Layers, Server } from 'lucide-react';
import { useState } from 'react';
import { EnvironmentVariablePanel } from './EnvironmentVariablesPanel';
import { AppDetailItem, SectionHeader } from './shared';

export default function ApplicationSection({
    environment,
    appRef,
}: {
    environment: Environment;
    appRef: React.MutableRefObject<HTMLDivElement | null>;
}) {
    const { data, setData, patch, processing, reset, errors, clearErrors } = useForm({
        environment_variables: environment.environment_variables,
    });
    const {
        props: { currentTeam, currentEnvironment, currentProject },
    } = usePage<SharedData>();

    const [isEditing, setIsEditing] = useState(false);
    return (
        <div className="z-10 space-y-4">
            <SectionHeader icon={<Server className="h-4 w-4 text-gray-500" />} title="Application" />

            <Card ref={appRef}>
                <CardTitle className="flex items-center justify-between">
                    <span>App</span>
                    <div className="flex items-center space-x-2">
                        <Badge variant="outline" className="bg-white">
                            Web
                        </Badge>
                    </div>
                </CardTitle>
                <CardContent className="space-y-4">
                    <AppDetailItem
                        icon={<Layers className="h-4 w-4 text-gray-500" />}
                        label="Environment variables"
                        value={`${Object.keys(environment.environment_variables).length}`}
                        action={
                            <Button size="xs" variant="secondary" onClick={() => setIsEditing(!isEditing)}>
                                <ChevronRight className="h-2 w-2 text-gray-700" />
                            </Button>
                        }
                    />
                </CardContent>
            </Card>

            {isEditing && (
                <EnvironmentVariablePanel
                    isOpen={isEditing}
                    allowEdit={!processing}
                    onClose={() => setIsEditing(false)}
                    initialVariables={environment.environment_variables}
                    onSave={() => {
                        console.log('save');
                        patch(route('teams.projects.environments.update', [currentTeam?.slug, currentProject?.slug, environment.id]));
                    }}
                    onChange={(variables) => {
                        setData('environment_variables', variables);
                    }}
                    title="Application Environment variables"
                />
            )}
        </div>
    );
}
