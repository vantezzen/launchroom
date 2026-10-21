import { Info } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * Shown before connecting GitHub when this dashboard's address isn't on the
 * public internet (localhost, a LAN name): github.com refuses webhooks to it,
 * so the app is created without one and pushes arrive by polling.
 */
export function WebhookReachabilityNote({
    webhookUrl,
}: {
    webhookUrl: string;
}) {
    let host = webhookUrl;
    try {
        host = new URL(webhookUrl).host;
    } catch {
        // Keep the raw value.
    }

    return (
        <Alert role="status" className="text-left">
            <Info aria-hidden="true" />
            <AlertTitle>GitHub can’t reach this dashboard</AlertTitle>
            <AlertDescription>
                <p>
                    <span className="type-mono-small">{host}</span> isn’t on the
                    public internet, so the app gets no webhook: launchroom
                    checks your branches every minute and pull request previews
                    stay off. For instant deploys, set{' '}
                    <code className="type-mono-small">APP_URL</code> to a public
                    address first.
                </p>
            </AlertDescription>
        </Alert>
    );
}
