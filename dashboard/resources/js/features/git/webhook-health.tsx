import { Status } from '@/components/status';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';

const states: Record<App.Enums.Git.WebhookHealth, StatusState> = {
    healthy: 'active',
    stale: 'warning',
    never: 'idle',
};

const labels: Record<App.Enums.Git.WebhookHealth, string> = {
    healthy: 'Pushes arrive instantly',
    stale: 'No pushes from GitHub lately: checking every minute',
    never: 'Pushes are checked every minute until GitHub reaches this dashboard',
};

const deliveryLabels: Record<App.Enums.Git.WebhookDeliveryStatus, string> = {
    received: 'queued',
    processed: 'processed',
    ignored: 'ignored',
    failed: 'failed',
};

/**
 * Whether GitHub's webhooks reach this dashboard (docs/06 §2), as one
 * health line: when they don't, launchroom polls tracked branches every
 * minute instead.
 */
export function WebhookHealth({
    webhook,
}: {
    webhook: App.Data.Git.WebhookHealthData;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <Status
                state={states[webhook.health]}
                label={labels[webhook.health]}
            />
            {webhook.lastReceivedAt !== null && (
                <p className="pl-5 type-small text-muted-foreground">
                    Last delivery <TimeAgo value={webhook.lastReceivedAt} />
                    {webhook.lastEvent !== null && (
                        <>
                            {' · '}
                            <span className="type-mono-small">
                                {webhook.lastEvent}
                            </span>
                            {webhook.lastStatus !== null &&
                                ` ${deliveryLabels[webhook.lastStatus]}`}
                        </>
                    )}
                </p>
            )}
        </div>
    );
}
