<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Actions\Git\ProcessWebhookDelivery;
use App\Enums\Git\WebhookDeliveryStatus;
use App\Models\Git\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use SensitiveParameter;
use Throwable;

/**
 * Processes one verified webhook delivery: a GitHub App's, or a GitLab/Gitea
 * connection's (docs/19 §5). The raw payload (which may name private
 * repositories and commit messages) only travels inside this encrypted job;
 * the database keeps metadata only. A finished delivery is never processed
 * again.
 */
#[Backoff([10, 60])]
#[Timeout(60)]
#[Tries(3)]
final class ProcessWebhookDeliveryJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $deliveryId,
        #[SensitiveParameter] public readonly string $payload,
    ) {}

    public function handle(ProcessWebhookDelivery $process): void
    {
        $delivery = WebhookDelivery::query()->with(['app', 'gitConnection'])->where('id', $this->deliveryId)->first();

        if (! $delivery instanceof WebhookDelivery || $delivery->status->isTerminal()) {
            return;
        }

        try {
            $delivery->transitionTo($process->handle($delivery, $this->payload));
        } catch (Throwable $e) {
            $delivery->transitionTo(WebhookDeliveryStatus::Failed, $e::class.': '.$e->getMessage());

            throw $e;
        }
    }
}
