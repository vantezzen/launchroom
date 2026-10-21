<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instance\ResetOwnerTwoFactor;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('launchroom:owner:reset-2fa {email : E-mail address of the instance owner}')]
#[Description('Remove two-factor authentication from a locked-out instance owner (audited)')]
final class ResetOwnerTwoFactorCommand extends Command
{
    public function handle(ResetOwnerTwoFactor $resetOwnerTwoFactor): int
    {
        $email = $this->argument('email');

        try {
            $owner = $resetOwnerTwoFactor->handle(is_string($email) ? $email : '', AuditActor::system());
        } catch (DomainRuleViolation $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Two-factor authentication removed for {$owner->email}. They can set it up again under Settings → Security.");

        return self::SUCCESS;
    }
}
