import { router } from '@inertiajs/react';
import { ConfirmPopover } from '@/components/patterns/confirm-popover';
import { Section } from '@/components/patterns/section';
import { Button } from '@/components/ui/button';
import { domain as saveDomain } from '@/routes/onboarding';
import { AppsDomainPicker } from './apps-domain-setup';

/**
 * Settings → Network, "App domains" (docs/05 §5): the same picker as the
 * address step of Get started. Changing it only affects new services;
 * existing addresses keep their hostname.
 */
export function AppDomainsSettings({
    setup,
}: {
    setup: App.Data.Domains.AppsDomainSetupData;
}) {
    const { domain } = setup;

    // No status pill in the header: the picker's own status line (free
    // addresses, or the live DNS check) says it once.

    return (
        <Section
            id="app-domains"
            title="App domains"
            description="Where new services get their address. Existing addresses stay as they are."
        >
            <AppsDomainPicker
                domain={domain}
                addresses={setup.addresses}
                freeWildcard={setup.freeWildcard}
                dashboardHost={setup.dashboardHost}
                usingFree={domain === null}
                freeAction={
                    <ConfirmPopover
                        align="start"
                        title="Use free addresses?"
                        consequence={`New services get sslip.io addresses instead of ones under ${domain}. Existing addresses keep working.`}
                        confirmLabel="Use free addresses"
                        onConfirm={() =>
                            new Promise<void>((resolve) =>
                                router.put(
                                    saveDomain().url,
                                    { appsDomain: null },
                                    {
                                        preserveScroll: true,
                                        onFinish: () => resolve(),
                                    },
                                ),
                            )
                        }
                    >
                        <Button variant="outline">Use free addresses</Button>
                    </ConfirmPopover>
                }
                mode="settings"
                done={setup.verified === true}
                framed
            />
        </Section>
    );
}
