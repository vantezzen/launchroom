import { Head, Link, useForm, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import InputError from '@/components/input-error';
import { UnsavedBar } from '@/components/patterns/unsaved-bar';
import { Status } from '@/components/status';
import { Input } from '@/components/ui/input';
import { SettingsCard, SettingsRow } from '@/features/settings/settings-card';
import { edit } from '@/routes/profile';
import { edit as teamSettings } from '@/routes/team';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

/**
 * Settings → Profile: name and email as rows; changes show the floating
 * unsaved bar instead of a Save button. Changing the email asks for the
 * current password in place.
 */
export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;
    const form = useForm({
        name: auth.user.name,
        email: auth.user.email,
        current_password: '',
    });
    const emailChanged = form.data.email !== auth.user.email;

    const save = () =>
        form.patch(ProfileController.update().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults({
                    name: form.data.name,
                    email: form.data.email,
                    current_password: '',
                });
                form.reset('current_password');
            },
        });

    return (
        <>
            <Head title="Profile" />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <SettingsCard title="Account">
                    <SettingsRow
                        label="Name"
                        description="Shown on deploys and in activity."
                        htmlFor="name"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                                autoComplete="name"
                            />
                            <InputError message={form.errors.name} />
                        </div>
                    </SettingsRow>
                    <SettingsRow
                        label="Email"
                        description={
                            mustVerifyEmail &&
                            auth.user.email_verified_at === null ? (
                                <span className="flex flex-col gap-1">
                                    <Status
                                        state="warning"
                                        label="Not verified yet"
                                    />
                                    <Link
                                        href={send()}
                                        as="button"
                                        className="w-fit text-signal underline-offset-4 hover:underline"
                                    >
                                        Send the verification email again
                                    </Link>
                                    {status === 'verification-link-sent' && (
                                        <span>A new link is on its way.</span>
                                    )}
                                </span>
                            ) : (
                                'For sign-in, invitations and alerts.'
                            )
                        }
                        htmlFor="email"
                        align="start"
                    >
                        <div className="flex w-full flex-col gap-1 sm:w-72">
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(event) =>
                                    form.setData('email', event.target.value)
                                }
                                required
                                autoComplete="username"
                            />
                            <InputError message={form.errors.email} />
                        </div>
                    </SettingsRow>
                    {emailChanged && (
                        <SettingsRow
                            label="Current password"
                            description="Needed to change your email."
                            htmlFor="current_password"
                        >
                            <div className="flex w-full flex-col gap-1 sm:w-72">
                                <Input
                                    id="current_password"
                                    type="password"
                                    value={form.data.current_password}
                                    onChange={(event) =>
                                        form.setData(
                                            'current_password',
                                            event.target.value,
                                        )
                                    }
                                    autoComplete="current-password"
                                />
                                <InputError
                                    message={form.errors.current_password}
                                />
                            </div>
                        </SettingsRow>
                    )}
                </SettingsCard>
            </form>

            <DeleteUser />

            <UnsavedBar
                open={form.isDirty}
                onReset={() => form.reset()}
                onSave={save}
                saving={form.processing}
            />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        { title: 'Settings', href: teamSettings() },
        {
            title: 'Profile',
            href: edit(),
        },
    ],
};
