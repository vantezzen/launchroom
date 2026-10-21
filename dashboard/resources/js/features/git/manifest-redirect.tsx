import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Posts the app manifest to GitHub (the manifest flow needs a browser form
 * POST to github.com). Submits automatically; the button is the fallback.
 */
export function ManifestRedirect({
    manifest,
}: {
    manifest: App.Data.Git.ManifestRedirectData;
}) {
    const form = useRef<HTMLFormElement>(null);

    useEffect(() => {
        form.current?.submit();
    }, [manifest.action]);

    return (
        <form
            ref={form}
            method="post"
            action={manifest.action}
            className="flex flex-col items-start gap-3"
        >
            <input type="hidden" name="manifest" value={manifest.manifest} />
            <p className="type-body text-muted-foreground" role="status">
                Opening GitHub to create the app…
            </p>
            <Button type="submit">Continue to GitHub</Button>
        </form>
    );
}
