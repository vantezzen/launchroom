<?php

declare(strict_types=1);

namespace App\Actions\Transfers;

use App\Enums\Transfers\ExportRunStatus;
use App\Enums\Transfers\TransferStatus;
use App\Exceptions\DomainRuleViolation;
use App\Models\Transfers\DataExportRun;
use App\Models\Transfers\DataTransfer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A fresh one-use link to a ready export (docs/14 §12.5): a random 256-bit
 * token, only its SHA-256 stored, bound to $user, valid for
 * `link_ttl_seconds`. A new link replaces the previous one.
 */
final readonly class CreateDownloadLink
{
    /**
     * @return array{token: string, expiresAt: CarbonImmutable}
     */
    public function handle(DataExportRun $run, User $user): array
    {
        return DB::transaction(function () use ($run, $user): array {
            $transfer = $run->transfer_id === null ? null : DataTransfer::query()->whereKey($run->transfer_id)->lockForUpdate()->first();

            if ($run->status !== ExportRunStatus::Ready || ! $transfer instanceof DataTransfer || $transfer->status !== TransferStatus::Ready || $transfer->expires_at->isPast()) {
                throw DomainRuleViolation::conflict('This download isn’t available any more. Start a new one.');
            }

            $token = 'lr_dl_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expiresAt = CarbonImmutable::now()->addSeconds(config()->integer('launchroom.transfers.link_ttl_seconds'));
            $transfer->forceFill([
                'link_token_hash' => hash('sha256', $token),
                'link_user_id' => $user->id,
                'link_expires_at' => $expiresAt,
            ])->save();

            return ['token' => $token, 'expiresAt' => $expiresAt];
        });
    }
}
