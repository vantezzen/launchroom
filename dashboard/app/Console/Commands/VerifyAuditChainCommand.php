<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Audit\VerifyAuditChain;
use App\Services\Audit\AuditVerificationStatus;
use App\Services\Crypto\Signer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:audit:verify {--from-checkpoint : Start at the newest valid checkpoint instead of the first entry} {--public-key : Print the key checkpoints are signed with and exit}')]
#[Description('Verify the audit log: its hash chain and signed checkpoints (docs/09 R34)')]
final class VerifyAuditChainCommand extends Command
{
    public function handle(VerifyAuditChain $verifyAuditChain, AuditVerificationStatus $status, Signer $signer): int
    {
        if ($this->option('public-key') === true) {
            $this->line($signer->kid.' '.$signer->publicKey());

            return self::SUCCESS;
        }

        $result = $verifyAuditChain->handle($this->option('from-checkpoint') === true);
        $status->record($result);

        if (! $result->valid) {
            $this->components->error("Audit chain broken at event #{$result->firstInvalidId} (after {$result->checked} valid entries): {$result->message}");

            return self::FAILURE;
        }

        $upTo = $result->verifiedUpTo instanceof CarbonImmutable ? 'verified up to '.$result->verifiedUpTo->toIso8601ZuluString() : 'no checkpoint yet';
        $from = $result->incremental ? ' since the last checkpoint' : '';
        $this->components->info("Audit chain intact ({$result->checked} entries{$from}, {$result->checkpoints} checkpoints, {$upTo}).");

        return self::SUCCESS;
    }
}
