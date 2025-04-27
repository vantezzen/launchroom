import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import HeadingSmall from '@/components/laravel/heading-small';
import InputError from '@/components/laravel/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { AlertTriangle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Instance settings',
        href: '/settings/instance',
    },
];

interface InstanceSettings {
    name: string;
    domain: string | null;
    publicly_accessible: boolean;
    ipv4: string;
    ipv6: string | null;
    admin_email: string;
}

type PageProps = {
    instanceSettings: InstanceSettings;
    status?: string;
};

export default function Instance() {
    const { instanceSettings, status } = usePage<PageProps>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: instanceSettings.name || '',
        domain: instanceSettings.domain || '',
        publicly_accessible: instanceSettings.publicly_accessible,
        ipv4: instanceSettings.ipv4 || '',
        ipv6: instanceSettings.ipv6 || '',
        admin_email: instanceSettings.admin_email || '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('instance.update'), {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Instance settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Instance settings" description="Update your server configuration and domain settings" />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Instance Name</Label>
                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Instance name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="domain">Domain</Label>
                            <Input
                                id="domain"
                                className="mt-1 block w-full"
                                value={data.domain}
                                onChange={(e) => setData('domain', e.target.value)}
                                autoComplete="domain"
                                placeholder="example.com"
                            />
                            <p className="text-muted-foreground text-sm">Optional. The main domain for your instance.</p>
                            <InputError message={errors.domain} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center gap-2">
                                <Switch
                                    id="publicly_accessible"
                                    checked={data.publicly_accessible}
                                    onCheckedChange={(checked) => setData('publicly_accessible', checked)}
                                />
                                <Label htmlFor="publicly_accessible">Publicly Accessible</Label>
                            </div>
                            <p className="text-muted-foreground text-sm">
                                Enable this if your server is accessible from the internet. Required for domain verification.
                            </p>
                            <InputError message={errors.publicly_accessible} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="ipv4">IPv4 Address</Label>
                            <Input
                                id="ipv4"
                                className="mt-1 block w-full"
                                value={data.ipv4}
                                onChange={(e) => setData('ipv4', e.target.value)}
                                required
                                placeholder="123.123.123.123"
                            />
                            <InputError message={errors.ipv4} />

                            <Alert variant="destructive">
                                <AlertTriangle className="h-4 w-4" />
                                <AlertTitle>Changing your server's IP address requires updating your DNS records!</AlertTitle>
                                <AlertDescription>
                                    If you change your server's IP address, make sure to update your DNS records to point to the new IP address. This
                                    may take some time to propagate.
                                </AlertDescription>
                            </Alert>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="ipv6">IPv6 Address</Label>
                            <Input
                                id="ipv6"
                                className="mt-1 block w-full"
                                value={data.ipv6}
                                onChange={(e) => setData('ipv6', e.target.value)}
                                placeholder="2001:0db8:85a3:0000:0000:8a2e:0370:7334"
                            />
                            <p className="text-muted-foreground text-sm">Optional. Leave empty if your server doesn't have an IPv6 address.</p>
                            <InputError message={errors.ipv6} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="admin_email">Admin Email</Label>
                            <Input
                                id="admin_email"
                                className="mt-1 block w-full"
                                value={data.admin_email}
                                onChange={(e) => setData('admin_email', e.target.value)}
                                required
                                autoComplete="email"
                                placeholder="admin@example.com"
                            />
                            <p className="text-muted-foreground text-sm">Used for SSL certificate provisioning and system notifications.</p>
                            <InputError message={errors.admin_email} />
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save changes</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-green-600">Saved.</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
