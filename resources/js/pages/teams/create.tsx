import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import InputError from '@/components/laravel/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import CardLayout from '@/layouts/card-layout';

export default function TeamCreate() {
    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        name: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('teams.store'));
    };

    return (
        <AppLayout>
            <Head title="Create a team" />

            <CardLayout title="Create a team" subtitle="Teams allow sharing and grouping your projects.">
                <div className="space-y-6">
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Launchroom HQ"
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
