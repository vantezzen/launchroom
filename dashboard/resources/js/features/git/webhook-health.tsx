import { Status } from '@/components/status';
import { MetaDivider } from '@/components/patterns/meta-line';
import type { StatusState } from '@/components/status';
import { TimeAgo } from '@/components/time-ago';

const states: Record<App.Enums.Git.WebhookHealth, StatusState> = {
    healthy: 'active',
    stale: 'warning',
    never: 'idle',
};

const labels: Record<App.Enums.Git.WebhookHealth, string> = {
    healthy: 'Pushes arrive right away',
    stale: 'A push didn’t arrive by webhook: checking every minute',
    never: 'Pushes are checked every minute until GitHub reaches this dashboard',
};

const deliveryLabels: Record<App.Enums.Git.WebhookDeliveryStatus, string> = {
    received: 'queued',
    processed: 'processed',
    ignored: 'ignored',
    failed: 'failed',
};

/**
 * Whether GitHub's webhooks deliver pushes (docs/06 §2.2), as one health
 * line. A quiet repository is healthy; after a missed push launchroom polls
 * tracked branches every minute until the next delivery.
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
                <p className="flex flex-wrap items-center gap-x-2 pl-5 type-small text-muted-foreground">
                    {webhook.missedAt !== null && (
                        <span>
                            Missed a push <TimeAgo value={webhook.missedAt} />
                        </span>
                    )}
                    {webhook.missedAt !== null && <MetaDivider />}
                    <span>
                        Last delivery <TimeAgo value={webhook.lastReceivedAt} />
                    </span>
                    {webhook.lastEvent !== null && <MetaDivider />}
                    {webhook.lastEvent !== null && (
                        <span>
                            <span className="type-mono-small">
                                {webhook.lastEvent}
                            </span>
                            {webhook.lastStatus !== null &&
                                ` ${deliveryLabels[webhook.lastStatus]}`}
                        </span>
                    )}
                </p>
            )}
        </div>
    );
}
