<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Security\SetClientCertificateMode;
use App\Data\Audit\AuditActor;
use App\Enums\ClientCertificateMode;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Host-access escape hatch for client certificates (mTLS, docs/03 §4.3):
 * `launchroom:client-certificates off` when servers were cut off. Without
 * an argument it prints the current mode. Audited as the system.
 */
#[Signature('launchroom:client-certificates {mode? : off, verify or require} {--force : require even if not every server is ready}')]
#[Description('Show or set whether servers must present client certificates')]
final class ClientCertificatesCommand extends Command
{
    public function handle(SetClientCertificateMode $setMode): int
    {
        $value = $this->argument('mode');

        if (! is_string($value)) {
            $this->line(ClientCertificateMode::current()->value);

            return self::SUCCESS;
        }

        $mode = ClientCertificateMode::tryFrom($value);

        if ($mode === null) {
            $this->components->error('The mode must be off, verify or require.');

            return self::INVALID;
        }

        try {
            $setMode->handle($mode, AuditActor::system(), (bool) $this->option('force'));
        } catch (DomainRuleViolation $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Client certificates: {$mode->value}.");

        return self::SUCCESS;
    }
}
