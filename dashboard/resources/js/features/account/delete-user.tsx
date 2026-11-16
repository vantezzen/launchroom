import { useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import PasswordInput from '@/components/password-input';
import { TypedConfirmDialog } from '@/components/patterns/typed-confirm-dialog';
import { SettingsCard, SettingsRow } from '@/components/settings/settings-card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/**
 * Profile's danger zone: deleting your own account, confirmed with your
 * password in the shared typed-confirm dialog (what goes, what stays).
 */
export default function DeleteUser() {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);
    const form = useForm({ password: '' });

    return (
        <SettingsCard title="Danger zone" setting="delete-account">
            <SettingsRow
                label="Delete your account"
                description="Removes you from every team. This can’t be undone."
            >
                <Button
                    variant="destructive"
                    size="sm"
                    data-test="delete-user-button"
                    onClick={() => setOpen(true)}
                >
                    Delete account
                </Button>
            </SettingsRow>
            <TypedConfirmDialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    form.reset();
                    form.clearErrors();
                }}
                title="Delete your account?"
                object={
                    auth.user
                        ? { name: auth.user.name, detail: auth.user.email }
                        : undefined
                }
                goes={[
                    'Your user, passkeys and API tokens',
                    'Your access to every team, its servers and projects',
                ]}
                kept={[
                    'Teams, projects and servers stay with their other members',
                ]}
                confirmLabel="Delete account"
                processing={form.processing}
                ready={form.data.password !== ''}
                error={form.errors.password}
                onConfirm={() =>
                    form.delete(ProfileController.destroy().url, {
                        preserveScroll: true,
                        onError: () => passwordInput.current?.focus(),
                        onFinish: () => form.reset('password'),
                    })
                }
            >
                <div className="grid gap-2">
                    <Label htmlFor="password">Your password</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        ref={passwordInput}
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        autoComplete="current-password"
                        autoFocus
                    />
                </div>
            </TypedConfirmDialog>
        </SettingsCard>
    );
}
