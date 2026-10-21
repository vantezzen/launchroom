<?php

declare(strict_types=1);

namespace App\Jobs\Git;

use App\Actions\Git\ProcessGitHubWebhook;
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
 * Processes one verified webhook delivery. The raw payload (which may name
 * private repositories and commit messages) only travels inside this
 * encrypted job; the database keeps metadata only.
 */
#[Backoff([10, 60])]
#[Timeout(60)]
#[Tries(3)]
final class ProcessGitHubWebhookJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $deliveryId,
        #[SensitiveParameter] public readonly string $payload,
    ) {}

    public function handle(ProcessGitHubWebhook $process): void
    {
        $delivery = WebhookDelivery::query()->with('app')->where('id', $this->deliveryId)->first();

        // Idempotent: a finished delivery is never processed again.
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
