import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CommandText } from '@/features/servers/server-notices';
import { retry } from '@/routes/projects/domains/certificate';

type Certificate = App.Data.Domains.CertificateData;

/**
 * Why a domain has no certificate and what to do (G16), with "Retry now"
 * once the certificate authority allows another try. Shown under the
 * domain's DNS → HTTPS → Traffic path.
 */
export function CertificateProblem({
    certificate,
    route,
    canRetry,
}: {
    certificate: Certificate | null;
    route: {
        project: string;
        environment: string;
        service: string;
        domain: string;
    };
    canRetry: boolean;
}) {
    const [busy, setBusy] = useState(false);

    if (
        !certificate ||
        certificate.status === 'issued' ||
        !certificate.problem
    ) {
        return null;
    }

    const limited = certificate.retryAfter !== null;

    return (
        <div className="flex flex-wrap items-start justify-between gap-3 border-t px-5 py-4">
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="type-body font-medium">
                    {certificate.status === 'failed'
                        ? 'The certificate couldn’t be issued'
                        : 'The certificate isn’t issued yet'}
                </span>
                <span className="type-small text-muted-foreground">
                    <CommandText text={certificate.problem} />
                </span>
            </div>
            {canRetry && !limited && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={() => {
                        setBusy(true);
                        router.post(
                            retry(route).url,
                            {},
                            {
                                preserveScroll: true,
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    <RefreshCw
                        className={busy ? 'motion-safe:animate-spin' : ''}
                    />
                    Retry now
                </Button>
            )}
        </div>
    );
}
