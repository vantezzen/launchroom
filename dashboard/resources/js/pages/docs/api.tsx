import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { CopyValue } from '@/components/copy-value';
import { Page, PageHeader } from '@/components/patterns/page';
import { MetaLine } from '@/components/patterns/meta-line';
import { Section } from '@/components/patterns/section';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { apiDocs } from '@/routes';
import { index as apiTokens } from '@/routes/api-tokens';

type Schema = {
    $ref?: string;
    type?: string | string[];
    description?: string;
    properties?: Record<string, Schema>;
    required?: string[];
    items?: Schema;
    additionalProperties?: Schema | boolean;
    enum?: (string | number)[];
    oneOf?: Schema[];
    pattern?: string;
    default?: unknown;
};

type Parameter = {
    name: string;
    in: 'path' | 'query' | 'header';
    required?: boolean;
    description?: string;
    schema: Schema;
};

type Operation = {
    operationId: string;
    summary: string;
    description?: string;
    tags: string[];
    'x-launchroom-ability'?: string;
    security?: unknown[];
    parameters?: Parameter[];
    requestBody?: { content: Record<string, { schema: Schema }> };
    responses: Record<
        string,
        { $ref?: string; content?: Record<string, { schema: Schema }> }
    >;
};

type Document = {
    info: { title: string; summary?: string; description?: string };
    tags: { name: string }[];
    paths: Record<string, Record<string, Operation>>;
    components: { schemas: Record<string, Schema> };
};

type Props = {
    document: Document;
    apiUrl: string;
};

const refName = (ref: string) => ref.split('/').pop() ?? ref;

/**
 * The API reference (docs/16 §2.4), rendered from the committed OpenAPI
 * document: operations grouped by tag, each with its ability, parameters,
 * body and response shapes. Monochrome; methods are text, not colours.
 */
export default function ApiDocs({ document, apiUrl }: Props) {
    const operations = Object.entries(document.paths).flatMap(
        ([path, methods]) =>
            Object.entries(methods).map(([method, operation]) => ({
                path,
                method,
                operation,
            })),
    );

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

                {document.tags.map((tag) => (
                    <Section key={tag.name} title={tag.name}>
                        <div className="divide-y overflow-hidden rounded-xl border bg-card shadow-card">
                            {operations
                                .filter(({ operation }) =>
                                    operation.tags.includes(tag.name),
                                )
                                .map(({ path, method, operation }) => (
                                    <OperationRow
                                        key={operation.operationId}
                                        path={path}
                                        method={method}
                                        operation={operation}
                                        schemas={document.components.schemas}
                                    />
                                ))}
                        </div>
                    </Section>
                ))}
            </Page>
        </>
    );
}

ApiDocs.layout = {
    breadcrumbs: [{ title: 'API reference', href: apiDocs() }],
};

function OperationRow({
    path,
    method,
    operation,
    schemas,
}: {
    path: string;
    method: string;
    operation: Operation;
    schemas: Record<string, Schema>;
}) {
    const ability = operation['x-launchroom-ability'];
    const body = operation.requestBody?.content['application/json']?.schema;
    const [status, success] =
        Object.entries(operation.responses).find(([code]) =>
            code.startsWith('2'),
        ) ?? [];
    const responseSchema = success?.content?.['application/json']?.schema;
    const stream = success?.content?.['text/event-stream'] !== undefined;
    const parameters = operation.parameters ?? [];

    return (
        <details id={operation.operationId} className="group">
            <summary className="flex cursor-pointer list-none items-center gap-3 px-5 py-3.5 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring">
                <ChevronRight
                    className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-90"
                    aria-hidden="true"
                />
                <span className="w-14 shrink-0 type-mono-small font-medium uppercase">
                    {method}
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate type-mono">{path}</span>
                    <span className="block type-small text-muted-foreground">
                        {operation.summary}
                    </span>
                </span>
                {ability ? (
                    <Badge variant="outline" className="hidden sm:inline-flex">
                        {ability}
                    </Badge>
                ) : (
                    operation.security?.length === 0 && (
                        <Badge
                            variant="secondary"
                            className="hidden sm:inline-flex"
                        >
                            public
                        </Badge>
                    )
                )}
            </summary>
            <div className="flex flex-col gap-5 border-t bg-recessed/40 px-5 py-5 sm:pl-[4.75rem]">
                {operation.description && (
                    <p className="type-body whitespace-pre-line text-muted-foreground">
                        {operation.description}
                    </p>
                )}
                {parameters.length > 0 && (
                    <Block title="Parameters">
                        <ul className="flex flex-col gap-2">
                            {parameters.map((parameter) => (
                                <li
                                    key={`${parameter.in}-${parameter.name}`}
                                    className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5"
                                >
                                    <code className="type-mono-small font-medium">
                                        {parameter.name}
                                    </code>
                                    <MetaLine className="type-small text-muted-foreground">
                                        {parameter.in}
                                        {parameter.required && 'required'}
                                        {typeLabel(parameter.schema)}
                                    </MetaLine>
                                    {parameter.description && (
                                        <span className="w-full type-small text-muted-foreground">
                                            {parameter.description}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Block>
                )}
                {body && (
                    <Block title="Body">
                        <SchemaView schema={body} schemas={schemas} />
                    </Block>
                )}
                <Block title={`Response ${status ?? ''}`}>
                    {stream ? (
                        <p className="type-small text-muted-foreground">
                            Server-sent events (text/event-stream).
                        </p>
                    ) : responseSchema ? (
                        <SchemaView schema={responseSchema} schemas={schemas} />
                    ) : (
                        <p className="type-small text-muted-foreground">
                            No body.
                        </p>
                    )}
                </Block>
            </div>
        </details>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <h3 className="type-label">{title}</h3>
            {children}
        </div>
    );
}

function typeLabel(schema: Schema): string {
    if (schema.$ref) {
        return refName(schema.$ref);
    }

    if (schema.oneOf) {
        return schema.oneOf.map(typeLabel).join(' | ');
    }

    if (schema.enum) {
        return schema.enum.map((value) => JSON.stringify(value)).join(' | ');
    }

    if (schema.type === 'array' && schema.items) {
        return `${typeLabel(schema.items)}[]`;
    }

    if (Array.isArray(schema.type)) {
        return schema.type.join(' | ');
    }

    return schema.type ?? 'any';
}

function resolve(schema: Schema, schemas: Record<string, Schema>): Schema {
    if (schema.$ref) {
        return schemas[refName(schema.$ref)] ?? schema;
    }

    if (schema.oneOf) {
        const concrete = schema.oneOf.find((part) => part.type !== 'null');

        return concrete ? resolve(concrete, schemas) : schema;
    }

    return schema;
}

/**
 * An object schema as an indented property list, two levels deep; deeper
 * references show their name only.
 */
function SchemaView({
    schema,
    schemas,
    depth = 0,
}: {
    schema: Schema;
    schemas: Record<string, Schema>;
    depth?: number;
}) {
    const resolved = resolve(schema, schemas);
    const target =
        resolved.type === 'array' && resolved.items
            ? resolve(resolved.items, schemas)
            : resolved;
    const properties = target.properties;

    if (!properties) {
        return (
            <code className="type-mono-small text-muted-foreground">
                {typeLabel(schema)}
            </code>
        );
    }

    return (
        <ul
            className={cn(
                'flex flex-col gap-1.5',
                depth > 0 && 'border-l pl-3',
            )}
        >
            {Object.entries(properties).map(([name, property]) => {
                const nested = resolve(property, schemas);
                const expandable =
                    depth < 2 &&
                    (nested.properties !== undefined ||
                        (nested.type === 'array' &&
                            nested.items !== undefined &&
                            resolve(nested.items, schemas).properties !==
                                undefined));

                return (
                    <li key={name} className="flex flex-col gap-1">
                        <span className="flex flex-wrap items-baseline gap-x-2">
                            <code className="type-mono-small font-medium">
                                {name}
                            </code>
                            <span className="type-small text-muted-foreground">
                                {typeLabel(property)}
                                {target.required?.includes(name)
                                    ? ''
                                    : ', optional'}
                            </span>
                        </span>
                        {property.description && (
                            <span className="type-small text-muted-foreground">
                                {property.description}
                            </span>
                        )}
                        {expandable && (
                            <SchemaView
                                schema={property}
                                schemas={schemas}
                                depth={depth + 1}
                            />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}
