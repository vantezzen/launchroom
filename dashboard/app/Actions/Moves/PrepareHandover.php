<?php

declare(strict_types=1);

namespace App\Actions\Moves;

use App\Enums\Moves\ControlPlaneMoveStatus;
use App\Models\Moves\ControlPlaneMove;
use App\Services\Backups\ControlPlaneKeyBundle;
use App\Services\Instance\DashboardOrigin;
use App\Services\Moves\HandoverStore;
use App\Services\Moves\InstanceFreeze;
use App\Support\Crypto\PublicKeyFormat;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Takes the final dump for the hand-over (docs/14 §13.4), in a queued job
 * while writes are frozen:
 *
 * 1. `pg_dump --format=custom` of the dashboard's database, streamed into
 *    encrypted chunks under a fresh key ({@see HandoverStore});
 * 2. the key bundle (APP_KEY, KEK, signing and registry keys, the Reverb
 *    secrets and Headscale's state; the same bundle as a control-plane
 *    backup, {@see ControlPlaneKeyBundle}) sealed to the new machine's
 *    X25519 key, next to the chunks;
 * 3. the chunk key sealed to the same key, in the manifest the new
 *    machine fetches (signed like every answer to it).
 *
 * Nothing here can be opened by this dashboard afterwards. A failure thaws
 * the dashboard and returns the move to "ready to hand over".
 */
final readonly class PrepareHandover
{
    public function __construct(
        private HandoverStore $store,
        private DashboardOrigin $origin,
    ) {}

    public function handle(int $moveId): void
    {
        $move = ControlPlaneMove::query()->whereKey($moveId)->first();

        if (! $move instanceof ControlPlaneMove || $move->status !== ControlPlaneMoveStatus::HandingOver || $move->receiver_public_key === null) {
            return;
        }

        try {
            $recipient = PublicKeyFormat::parseX25519($move->receiver_public_key) ?? throw new RuntimeException('The new server’s key is malformed.');
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
            $dump = $this->store->write($move->public_id, $key, config()->integer('launchroom.backups.task_timeout_seconds'));
            $bundle = sodium_crypto_box_seal(ControlPlaneKeyBundle::collect(), $recipient);
            $this->store->writeKeys($move->public_id, $bundle);

            $manifest = [
                'kind' => 'launchroom-move/1',
                'move' => $move->public_id,
                'createdAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'appUrl' => $this->origin->installUrl(),
                'canonicalUrl' => $this->origin->canonical(),
                'database' => [...$dump],
                'sealedKey' => base64_encode(sodium_crypto_box_seal($key, $recipient)),
                'keys' => ['bytes' => strlen($bundle), 'sha256' => hash('sha256', $bundle)],
            ];
            sodium_memzero($key);

            DB::transaction(static function () use ($move, $manifest): void {
                $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

                if ($move->status === ControlPlaneMoveStatus::HandingOver) {
                    $move->handover = $manifest;
                    $move->transitionTo(ControlPlaneMoveStatus::HandoverReady);
                }
            });
        } catch (Throwable $e) {
            $this->store->delete($move->public_id);

            if (! $e instanceof RuntimeException) {
                report($e);
            }

            DB::transaction(static function () use ($move, $e): void {
                $move = ControlPlaneMove::query()->whereKey($move->id)->lockForUpdate()->firstOrFail();

                if ($move->status === ControlPlaneMoveStatus::HandingOver) {
                    InstanceFreeze::thaw();
                    $move->frozen_at = null;
                    $move->transitionTo(ControlPlaneMoveStatus::ReadyToHandOver, 'The final dump failed: '.($e instanceof RuntimeException ? $e->getMessage() : 'see the dashboard logs.').' Nothing changed; try again.');
                }
            });
        }
    }
}
