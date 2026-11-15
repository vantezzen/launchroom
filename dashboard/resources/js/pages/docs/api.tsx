import { Head, Link } from '@inertiajs/react';
import { CopyValue } from '@/components/copy-value';
import { Page, PageHeader } from '@/components/patterns/page';
import { Section } from '@/components/patterns/section';
import { ApiOperations } from '@/features/api-docs/api-operations';
import type { OpenApiDocument } from '@/features/api-docs/api-operations';
import { apiDocs } from '@/routes';
import { index as apiTokens } from '@/routes/api-tokens';

type Props = {
    document: OpenApiDocument;
    apiUrl: string;
};

/**
 * The API reference (docs/16 §2.4), rendered from the committed OpenAPI
 * document: operations grouped by tag, each with its ability, parameters,
 * body and response shapes. Monochrome; methods are text, not colours.
 */
export default function ApiDocs({ document, apiUrl }: Props) {
    return (
        <>
            <Head title="API reference" />
            <Page size="narrow">
                <PageHeader
                    title="API reference"
                    description={document.info.summary}
                />

                <Section title="Basics">
                    <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 type-body shadow-card">
                        <p className="text-muted-foreground">
                            Send a token from{' '}
                            <Link
                                href={apiTokens()}
                                className="text-signal underline decoration-signal/40 underline-offset-4"
                            >
                                Settings → API tokens
                            </Link>{' '}
                            as a bearer token. It acts as you in one team, with
                            the abilities you gave it.
                        </p>
                        <CopyValue
                            value={`curl -H "Authorization: Bearer $LAUNCHROOM_TOKEN" ${apiUrl}/projects`}
                            label="example request"
                        />
                        <p className="text-muted-foreground">
                            Errors are{' '}
                            <code className="type-mono-small">
                                {'{"error": {"code", "message"}}'}
                            </code>
                            . Lists page with{' '}
                            <code className="type-mono-small">cursor</code>.
                            Creates accept an{' '}
                            <code className="type-mono-small">
                                Idempotency-Key
                            </code>{' '}
                            header.
                        </p>
                    </div>
                </Section>

                <ApiOperations document={document} />
            </Page>
        </>
    );
}

ApiDocs.layout = {
    breadcrumbs: [{ title: 'API reference', href: apiDocs() }],
};
