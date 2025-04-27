import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import FormErrors from '@/components/ui/form-errors';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import SettingsLayout from '@/layouts/settings-layout';
import { SharedData, Team } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const environmentTypes = [
    { value: 'production', label: 'Production' },
    { value: 'staging', label: 'Staging' },
    { value: 'testing', label: 'Testing' },
    { value: 'development', label: 'Development' },
] as const;

type EnvironmentType = (typeof environmentTypes)[number]['value'];

interface PageProps extends SharedData {
    userTeams: Team[];
}

export default function EnvironmentSettings() {
    const page = usePage<PageProps>();
    const { auth, currentTeam, currentProject, currentEnvironment, userTeams } = page.props;
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isTransferDialogOpen, setIsTransferDialogOpen] = useState(false);

    // Project settings form - initialize with default values
    const projectForm = useForm({
        name: '',
        repository: '',
    });

    // Environment settings form - initialize with default values
    const settingsForm = useForm({
        name: '',
        type: 'development' as EnvironmentType,
        branch: '',
    });

    // Project transfer form
    const transferForm = useForm({
        team_id: '',
    });

    // Project delete form
    const deleteForm = useForm({
        project_name: '',
    });

    // If we don't have the required data, show loading
    if (!currentTeam || !currentProject || !currentEnvironment) {
        return <div>Loading...</div>;
    }

    // Update form values once we have data
    if (projectForm.data.name === '' && currentProject) {
        projectForm.setData({
            name: currentProject.name,
            repository: currentProject.repository,
        });
    }

    if (settingsForm.data.name === '' && currentEnvironment) {
        settingsForm.setData({
            name: currentEnvironment.name,
            type: currentEnvironment.type,
            branch: currentEnvironment.branch || '',
        });
    }

    const submitProjectForm = (e: React.FormEvent) => {
        e.preventDefault();
        projectForm.patch(
            route('teams.projects.update', {
                team: currentTeam.slug,
                project: currentProject.slug,
            }),
            {
                preserveScroll: true,
            },
        );
    };

    const submitSettingsForm = (e: React.FormEvent) => {
        e.preventDefault();
        settingsForm.patch(
            route('teams.projects.environments.settings.update', {
                team: currentTeam.slug,
                project: currentProject.slug,
                environment: currentEnvironment.id,
            }),
            {
                preserveScroll: true,
            },
        );
    };

    const submitTransferForm = (e: React.FormEvent) => {
        e.preventDefault();
        transferForm.post(
            route('teams.projects.transfer', {
                team: currentTeam.slug,
                project: currentProject.slug,
            }),
            {
                onSuccess: () => {
                    setIsTransferDialogOpen(false);
                },
            },
        );
    };

    const submitDeleteForm = (e: React.FormEvent) => {
        e.preventDefault();
        deleteForm.delete(
            route('teams.projects.delete', {
                team: currentTeam.slug,
                project: currentProject.slug,
            }),
            {
                onSuccess: () => {
                    setIsDeleteDialogOpen(false);
                },
            },
        );
    };

    return (
        <SettingsLayout title="Settings" subtitle="Manage your environment and project settings">
            <Head title={`Settings - ${currentProject.name}`} />

            <div className="space-y-10">
                {/* Project Settings Section */}
                <Card>
                    <CardHeader>
                        <CardTitle>Project Settings</CardTitle>
                        <CardDescription>Configure project-wide settings that apply to all environments</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitProjectForm} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="project_name">Project Name</Label>
                                <Input
                                    id="project_name"
                                    value={projectForm.data.name}
                                    onChange={(e) => projectForm.setData('name', e.target.value)}
                                    placeholder="My Project"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="project_repository">Repository</Label>
                                <Input
                                    id="project_repository"
                                    value={projectForm.data.repository}
                                    onChange={(e) => projectForm.setData('repository', e.target.value)}
                                    placeholder="username/repository"
                                />
                            </div>

                            <FormErrors errors={projectForm.errors} />

                            <Button type="submit" disabled={projectForm.processing}>
                                Update Project
                            </Button>
                        </form>

                        <Separator className="my-6" />

                        {/* Transfer Project Section */}
                        <div>
                            <Label className="text-base">Transfer Project</Label>
                            <p className="text-muted-foreground mb-4 text-sm">Transfer this project to another team</p>

                            <Dialog open={isTransferDialogOpen} onOpenChange={setIsTransferDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="outline">Transfer Project</Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Transfer Project</DialogTitle>
                                        <DialogDescription>
                                            This will transfer the project and all its environments to another team. You must be a member of the
                                            target team.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <form onSubmit={submitTransferForm} className="space-y-4 py-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="team_id">Select Team</Label>
                                            <Select
                                                value={transferForm.data.team_id}
                                                onValueChange={(value) => transferForm.setData('team_id', value)}
                                            >
                                                <SelectTrigger id="team_id">
                                                    <SelectValue placeholder="Select a team" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {userTeams &&
                                                        userTeams.map((team) => (
                                                            <SelectItem key={team.id} value={team.id}>
                                                                {team.name}
                                                            </SelectItem>
                                                        ))}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <FormErrors errors={transferForm.errors} />

                                        <DialogFooter>
                                            <Button variant="outline" type="button" onClick={() => setIsTransferDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button type="submit" disabled={transferForm.processing || !transferForm.data.team_id}>
                                                Transfer Project
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>

                        <Separator className="my-6" />

                        {/* Delete Project */}
                        <div>
                            <Label className="text-destructive text-base">Danger Zone</Label>
                            <p className="text-muted-foreground mb-4 text-sm">Permanently delete this project and all its environments</p>

                            <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
                                <DialogTrigger asChild>
                                    <Button variant="destructive">Delete Project</Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogHeader>
                                        <DialogTitle>Delete Project</DialogTitle>
                                        <DialogDescription>
                                            This action cannot be undone. This will permanently delete the project, all its environments, and all
                                            associated data.
                                        </DialogDescription>
                                    </DialogHeader>

                                    <form onSubmit={submitDeleteForm} className="space-y-4 py-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="delete_project_name">
                                                Type <span className="font-medium">{currentProject.name}</span> to confirm
                                            </Label>
                                            <Input
                                                id="delete_project_name"
                                                value={deleteForm.data.project_name}
                                                onChange={(e) => deleteForm.setData('project_name', e.target.value)}
                                                placeholder={currentProject.name}
                                            />
                                        </div>

                                        <FormErrors errors={deleteForm.errors} />

                                        <DialogFooter>
                                            <Button variant="outline" type="button" onClick={() => setIsDeleteDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                type="submit"
                                                disabled={deleteForm.processing || deleteForm.data.project_name !== currentProject.name}
                                            >
                                                Delete Project
                                            </Button>
                                        </DialogFooter>
                                    </form>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </CardContent>
                </Card>

                {/* Environment Settings Section */}
                <Card>
                    <CardHeader>
                        <CardTitle>Environment Settings</CardTitle>
                        <CardDescription>Update your environment configuration</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitSettingsForm} className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Environment Name</Label>
                                <Input
                                    id="name"
                                    value={settingsForm.data.name}
                                    onChange={(e) => settingsForm.setData('name', e.target.value)}
                                    placeholder="Production"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="type">Environment Type</Label>
                                <Select
                                    value={settingsForm.data.type}
                                    onValueChange={(value: EnvironmentType) => settingsForm.setData('type', value)}
                                >
                                    <SelectTrigger id="type">
                                        <SelectValue placeholder="Select environment type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {environmentTypes.map((type) => (
                                            <SelectItem key={type.value} value={type.value}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="branch">Git Branch</Label>
                                <Input
                                    id="branch"
                                    value={settingsForm.data.branch}
                                    onChange={(e) => settingsForm.setData('branch', e.target.value)}
                                    placeholder="main"
                                />
                            </div>

                            <FormErrors errors={settingsForm.errors} />

                            <Button type="submit" disabled={settingsForm.processing}>
                                Save Changes
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
