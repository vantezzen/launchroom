<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instance\CreateSetupToken;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:setup-token {--ttl= : Minutes until the token expires} {--plain : Print only the token}')]
#[Description('Create the one-time first-run setup token (replaces any previous one)')]
final class SetupTokenCommand extends Command
{
    public function handle(CreateSetupToken $createSetupToken): int
    {
        $ttlOption = $this->option('ttl');
        $ttl = is_numeric($ttlOption) ? (int) $ttlOption : config()->integer('launchroom.setup.token_ttl_minutes');

        try {
            $token = $createSetupToken->handle($ttl, AuditActor::system());
        } catch (DomainRuleViolation $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('plain') === true) {
            $this->line($token);

            return self::SUCCESS;
        }

        $url = rtrim(config()->string('app.url'), '/').'/setup?token='.$token;

        $this->components->info("Setup token created (valid for {$ttl} minutes, single use).");
        $this->line("  Open {$url}");

        return self::SUCCESS;
    }
}
