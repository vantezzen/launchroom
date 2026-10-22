import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Section } from '@/components/patterns/section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { requestJson } from '@/features/projects/http';
import { domain as saveDomain } from '@/routes/onboarding';
import { check as checkDomain } from '@/routes/onboarding/domain';
import { DnsCheckResult } from './dns-check-result';
import { PlatformDnsRecords } from './platform-dns-records';

export function AppDomainsSettings({
    domain,
    addresses,
}: {
    domain: string | null;
    addresses: string[];
}) {
    const [value, setValue] = useState(domain ?? '');
    const [saving, setSaving] = useState(false);
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<App.Data.Domains.DnsCheckData | null>(
        null,
    );

    useEffect(() => {
        setValue(domain ?? '');
        setResult(null);
    }, [domain]);

    const check = async () => {
        if (!domain) return;
        setChecking(true);
        try {
            setResult(await requestJson('post', checkDomain().url, { domain }));
            setError(null);
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The DNS check failed.',
            );
        } finally {
            setChecking(false);
        }
    };

    return (
        <Section
            id="app-domains"
            title="App domains"
            description="The default address for new services. Existing addresses stay as they are."
        >
            <div className="flex min-w-0 flex-col gap-5 rounded-xl border bg-card p-5">
                <form
                    className="flex flex-col gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.put(
                            saveDomain().url,
                            { appsDomain: value.trim() || null },
                            {
                                preserveScroll: true,
                                onStart: () => setSaving(true),
                                onFinish: () => setSaving(false),
                                onError: (errors) =>
                                    setError(
                                        errors.appsDomain ?? 'Saving failed.',
                                    ),
                                onSuccess: () => setError(null),
                            },
                        );
                    }}
                >
                    <Label htmlFor="settings-app-domain">
                        Domain for your apps
                    </Label>
                    <div className="flex flex-wrap gap-2">
                        <Input
                            id="settings-app-domain"
                            value={value}
                            onChange={(event) => setValue(event.target.value)}
                            placeholder="apps.example.com"
                            autoComplete="off"
                            spellCheck={false}
                            className="min-w-0 flex-1 basis-56 type-mono"
                        />
                        <Button
                            disabled={saving || value.trim() === (domain ?? '')}
                            type="submit"
                        >
                            Save
                        </Button>
                    </div>
                    <p className="type-small text-muted-foreground">
                        Leave empty to use free sslip.io addresses. Custom
                        domains for each app remain available.
                    </p>
                </form>
                <InputError message={error ?? undefined} />
                {domain ? (
                    <>
                        <PlatformDnsRecords
                            domain={domain}
                            addresses={addresses}
                        />
                        {result && (
                            <DnsCheckResult result={result} compact wildcard />
                        )}
                        <div>
                            <Button
                                variant="outline"
                                disabled={checking}
                                onClick={() => void check()}
                            >
                                {checking ? 'Checking…' : 'Check DNS'}
                            </Button>
                        </div>
                    </>
                ) : (
                    <p className="type-small text-muted-foreground">
                        Using free sslip.io addresses.
                    </p>
                )}
            </div>
        </Section>
    );
}
