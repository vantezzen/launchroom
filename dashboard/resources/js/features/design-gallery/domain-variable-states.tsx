import { useState } from 'react';
import type { ReactNode } from 'react';
import { StagedBar } from '@/components/patterns/unsaved-bar';
import { Button } from '@/components/ui/button';
import {
    CloudflareRecordHint,
    ProxyNote,
} from '@/features/domains/dns-check-result';
import {
    CheckFreshness,
    DomainPath,
} from '@/features/service-settings/domains-section';
import { ReferenceError } from '@/features/service-settings/variables-section';

/**
 * The domain card's live parts and the variables page's fixes (D109) with
 * sample data: the DNS → HTTPS → Traffic path turning green, the ticking
 * check line, provider hints, broken-reference suggestions and the staged
 * bar. Buttons only change this page.
 */

const secondsAgo = (seconds: number): string =>
    new Date(Date.now() - seconds * 1000).toISOString();

function Example({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <span className="type-label text-muted-foreground">{title}</span>
            {children}
        </div>
    );
}

function LivePath() {
    const [found, setFound] = useState(false);

    return (
        <div className="@container/settings flex flex-col gap-3">
            <div className="overflow-hidden rounded-xl border bg-card">
                <DomainPath
                    steps={[
                        {
                            label: 'DNS',
                            step: found
                                ? {
                                      state: 'active',
                                      title: 'Points at your servers',
                                      detail: 'Checked now',
                                  }
                                : {
                                      state: 'warning',
                                      title: 'No record yet',
                                      detail: 'Checked 20 seconds ago',
                                  },
                        },
                        {
                            label: 'HTTPS',
                            step: found
                                ? {
                                      state: 'active',
                                      title: 'Certificate issued',
                                      detail: 'Let’s Encrypt',
                                  }
                                : {
                                      state: 'idle',
                                      title: 'Issued on the first visit',
                                      detail: 'Once DNS points here',
                                  },
                        },
                        {
                            label: 'Traffic',
                            step: {
                                state: 'active',
                                title: 'Served by fra-1',
                            },
                        },
                    ]}
                />
            </div>
            <Button
                variant="outline"
                size="sm"
                className="w-fit"
                onClick={() => setFound((value) => !value)}
            >
                {found ? 'Back to waiting' : 'DNS record found'}
            </Button>
        </div>
    );
}

export function DomainVariableStates() {
    return (
        <div className="flex flex-col gap-8">
            <Example title="Domain path: steps settle in as they turn green">
                <LivePath />
            </Example>
            <Example title="DNS check line">
                <div className="flex flex-col gap-2 rounded-xl border bg-recessed px-5 py-4">
                    <CheckFreshness
                        checkedAt={secondsAgo(4)}
                        checking={false}
                        live
                    />
                    <CheckFreshness checkedAt={secondsAgo(4)} checking live />
                    <CheckFreshness
                        checkedAt={secondsAgo(600)}
                        checking={false}
                        live={false}
                    />
                </div>
            </Example>
            <Example title="Provider hints">
                <div className="flex flex-col gap-3">
                    <CloudflareRecordHint wildcard={false} />
                    <CloudflareRecordHint wildcard />
                    <ProxyNote proxy="Cloudflare" />
                </div>
            </Example>
            <Example title="Broken reference with suggestions">
                <div className="flex flex-col gap-3 rounded-xl border bg-card px-5 py-4">
                    <ReferenceError
                        message="${{analytics.PUBLIC_URL}} refers to a service that doesn't exist in this environment"
                        fix={{
                            expression: '${{analytics.PUBLIC_URL}}',
                            suggestions: [
                                '${{api.PUBLIC_URL}}',
                                '${{web.PUBLIC_URL}}',
                            ],
                        }}
                        canRewrite
                        onSuggestion={() => undefined}
                        onEdit={() => undefined}
                    />
                    <ReferenceError
                        message="${{postgres.DB_URL}} refers to a variable that doesn't exist"
                        fix={{
                            expression: '${{postgres.DB_URL}}',
                            suggestions: [],
                        }}
                        canRewrite
                        onSuggestion={() => undefined}
                        onEdit={() => undefined}
                    />
                </div>
            </Example>
            <Example title="Staged bar: several services, then one">
                <div className="relative h-20 rounded-lg bg-recessed">
                    <StagedBar
                        placement="inline"
                        count={4}
                        countLabel="4 changes in 2 services"
                        summary="web will restart"
                        onReview={() => undefined}
                        onDeploy={() => undefined}
                    />
                </div>
                <div className="relative h-20 rounded-lg bg-recessed">
                    <StagedBar
                        placement="inline"
                        count={2}
                        summary="web will restart"
                        onReview={() => undefined}
                        onDiscard={() => undefined}
                        onDeploy={() => undefined}
                    />
                </div>
                <div className="relative h-20 rounded-lg bg-recessed">
                    <StagedBar
                        placement="inline"
                        count={1}
                        summary="web will restart"
                        onReview={() => undefined}
                        onDeploy={() => undefined}
                        disabledReason="Fix the broken references first"
                    />
                </div>
            </Example>
        </div>
    );
}
