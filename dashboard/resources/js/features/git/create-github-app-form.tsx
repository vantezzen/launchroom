import { Form } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/git/github/manifest';

/**
 * Starts the GitHub App manifest flow: GitHub creates an app with the
 * permissions launchroom needs and hands its credentials back. One button;
 * the organization and GitHub Enterprise Server are optional details.
 */
export function CreateGitHubAppForm({ primary }: { primary: boolean }) {
    return (
        <Form
            {...store.form()}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <Collapsible
                        defaultOpen={
                            errors.organization !== undefined ||
                            errors.githubUrl !== undefined
                        }
                    >
                        <CollapsibleTrigger className="group inline-flex items-center gap-1 type-label text-muted-foreground hover:text-foreground">
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 transition-transform group-data-[state=open]:rotate-90 motion-reduce:transition-none"
                            />
                            For an organization or GitHub Enterprise Server
                        </CollapsibleTrigger>
                        <CollapsibleContent className="grid gap-4 pt-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="github-organization">
                                    Organization
                                </Label>
                                <Input
                                    id="github-organization"
                                    name="organization"
                                    autoComplete="off"
                                    spellCheck={false}
                                    placeholder="acme"
                                    className="type-mono"
                                />
                                <p className="type-small text-muted-foreground">
                                    Empty: your personal account.
                                </p>
                                <InputError message={errors.organization} />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="github-url">Server URL</Label>
                                <Input
                                    id="github-url"
                                    name="githubUrl"
                                    type="url"
                                    inputMode="url"
                                    placeholder="https://github.example.com"
                                    autoComplete="off"
                                    spellCheck={false}
                                    className="type-mono"
                                />
                                <p className="type-small text-muted-foreground">
                                    Only for GitHub Enterprise Server.
                                </p>
                                <InputError message={errors.githubUrl} />
                            </div>
                        </CollapsibleContent>
                    </Collapsible>

                    <div>
                        <Button
                            type="submit"
                            variant={primary ? 'default' : 'outline'}
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {primary ? 'Connect GitHub' : 'Create another app'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
