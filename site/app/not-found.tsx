import { SiteFooter } from '@/components/marketing/site-footer';
import { SiteHeader } from '@/components/marketing/site-header';
import { ButtonLink } from '@/components/marketing/button';

export default function NotFound() {
    return (
        <>
            <SiteHeader />
            <main id="main" className="flex flex-1 flex-col items-center justify-center gap-4 px-4 py-32 text-center">
                <p className="font-mono text-sm text-muted-foreground">404</p>
                <h1 className="type-title">This page doesn&apos;t exist.</h1>
                <p className="max-w-[44ch] text-muted-foreground">
                    The link may be old, or the page moved into the docs.
                </p>
                <div className="mt-4 flex gap-3">
                    <ButtonLink href="/">Home</ButtonLink>
                    <ButtonLink href="/docs" variant="secondary">
                        Docs
                    </ButtonLink>
                </div>
            </main>
            <SiteFooter />
        </>
    );
}
