import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import RepositorySelector from '@/components/github/RepositorySelector';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import CardLayout from '@/layouts/card-layout';
import { Repository, SharedData } from '@/types';

export default function ProjectCreate({ repositories }: { repositories: Repository[] }) {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        name: '',
        repository: '',
    });
    const [selectedRepository, setSelectedRepository] = useState<Repository | null>(null);

    const {
        props: { currentTeam },
    } = usePage<SharedData>();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('teams.projects.store', { team: currentTeam.slug }));
    };

    return (
        <AppLayout>
            <Head title="Create a project" />

            <CardLayout title="Create a project" subtitle="Start a new project from any of your connected repositories.">
                <div className="space-y-6">
                    {!selectedRepository ? (
                        <RepositorySelector
                            repositories={repositories}
                            onSelect={(repo) => {
                                setSelectedRepository(repo);
                                setData('name', repo.full_name);
                                setData('repository', repo.html_url);
                            }}
                        />
                    ) : (
                        <form onSubmit={submit} className="space-y-6">
                            <p className="text-zinc-500">
                                We will be creating a new project from the repository{' '}
                                <span className="font-semibold">{selectedRepository.full_name}</span>. Is that correct?
                            </p>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Create</Button>

                                <Button variant="secondary" disabled={processing} onClick={() => setSelectedRepository(null)} type="button">
                                    Cancel
                                </Button>

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
                    )}
                </div>
            </CardLayout>
        </AppLayout>
    );
}
