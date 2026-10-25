import { useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/git/servers';

/**
 * "Any git server" (docs/19 §4.2): one clone URL. SSH addresses continue in
 * the guided setup (host key, deploy key); public HTTPS ones are ready.
 */
export function AddGitServerForm({
    canAllowPrivateNetwork,
}: {
    canAllowPrivateNetwork: boolean;
}) {
    const form = useForm({ url: '', allowPrivateNetwork: false });

    return (
        <form
            className="flex animate-enter flex-col gap-5"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(store().url, { preserveScroll: true });
            }}
        >
            <div className="grid max-w-xl gap-2">
                <Label htmlFor="git-server-url">Clone URL</Label>
                <Input
                    id="git-server-url"
                    autoComplete="off"
                    spellCheck={false}
                    className="type-mono"
                    placeholder="git@git.example.com:team/app.git"
                    value={form.data.url}
                    onChange={(event) =>
                        form.setData('url', event.target.value)
                    }
                />
                <p className="type-small text-muted-foreground">
                    SSH (
                    <span className="type-mono-small">
                        git@host:group/app.git
                    </span>
                    ,{' '}
                    <span className="type-mono-small">
                        ssh://git@host:2222/app.git
                    </span>
                    ) for private repositories with a deploy key, or a public{' '}
                    <span className="type-mono-small">https://</span> URL.
                    launchroom checks for new commits every minute.
                </p>
                <InputError message={form.errors.url} />
            </div>
            {canAllowPrivateNetwork && (
                <div className="flex items-start gap-2">
                    <Checkbox
                        id="git-server-private"
                        checked={form.data.allowPrivateNetwork}
                        onCheckedChange={(checked) =>
                            form.setData(
                                'allowPrivateNetwork',
                                checked === true,
                            )
                        }
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="git-server-private">
                            This server is on my private network
                        </Label>
                        <p className="type-small text-muted-foreground">
                            Allows LAN addresses. Only instance owners can turn
                            this on, and the change is recorded in the audit
                            log.
                        </p>
                        <InputError message={form.errors.allowPrivateNetwork} />
                    </div>
                </div>
            )}
            <div>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {form.processing ? 'Looking up the server' : 'Continue'}
                </Button>
            </div>
        </form>
    );
}
