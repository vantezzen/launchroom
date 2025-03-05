import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import CardLayout from '@/layouts/card-layout';
import { SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { FormEventHandler } from 'react';

function GithubConnection() {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        github_token: '',
    });
    const {
        props: { currentTeam },
    } = usePage<SharedData>();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('teams.update', { team: currentTeam.slug }));
    };

    return (
        <AppLayout>
            <Head title="GitHub Connection" />

            <CardLayout
                title="GitHub Connection"
                subtitle="Connect your GitHub account to connect your repositories. To connect your GitHub account, you need to create a personal access token. You can create a new token on the GitHub website."
            >
                <div className="space-y-6">
                    <a
                        href="https://github.com/settings/tokens/new?description=launchroom&scopes=repo,admin:repo_hook&default_expires_at=none"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <Button className="mb-3 w-full">
                            <ExternalLink />
                            Create a personal access token
                        </Button>
                    </a>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="github_token">Access Token</Label>

                            <Input
                                id="github_token"
                                className="mt-1 block w-full"
                                value={data.github_token}
                                onChange={(e) => setData('github_token', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="ghp_1234567890"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </CardLayout>
        </AppLayout>
    );
}

export default GithubConnection;
