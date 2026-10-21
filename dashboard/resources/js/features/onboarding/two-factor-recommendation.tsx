import { Form, Link } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dismiss } from '@/routes/onboarding/two-factor';
import { edit as security } from '@/routes/security';

/**
 * Get started's two-factor nudge (docs/README D33): recommended for whoever
 * runs the instance, never required. "Not now" hides it for this user;
 * Settings → Security keeps offering it.
 */
export function TwoFactorRecommendation() {
    return (
        <section
            aria-labelledby="two-factor-recommendation"
            className="flex flex-col gap-4 rounded-xl border bg-card p-5 shadow-card sm:flex-row sm:items-center"
        >
            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-background text-muted-foreground">
                <ShieldCheck aria-hidden="true" className="size-5" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <h2 id="two-factor-recommendation" className="type-label">
                    Add two-factor sign-in
                </h2>
                <p className="type-small text-muted-foreground">
                    Recommended for the account that runs this instance. It
                    takes a minute with an authenticator app.
                </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Form {...dismiss.form()} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                        >
                            Not now
                        </Button>
                    )}
                </Form>
                <Button asChild variant="outline" size="sm">
                    <Link href={security()}>Set up</Link>
                </Button>
            </div>
        </section>
    );
}
