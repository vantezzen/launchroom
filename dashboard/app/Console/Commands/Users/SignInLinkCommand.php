<?php

declare(strict_types=1);

namespace App\Console\Commands\Users;

use App\Actions\Instance\IssueSignInLink;
use App\Data\Audit\AuditActor;
use App\Exceptions\DomainRuleViolation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `./launchroom reset-password EMAIL` (OD20): prints only the one-time URL
 * on stdout, so the helper can show it as it is. Errors go to stderr.
 */
#[Signature('launchroom:users:sign-in-link {email : E-mail address of the account}')]
#[Description('Print a one-time link to choose a new password (audited)')]
final class SignInLinkCommand extends Command
{
    public function handle(IssueSignInLink $issue): int
    {
        $email = $this->argument('email');

        try {
            $url = $issue->handle(is_string($email) ? $email : '', AuditActor::system());
        } catch (DomainRuleViolation $e) {
            $this->getOutput()->getErrorStyle()->writeln($e->getMessage());

            return self::FAILURE;
        }

        $this->line($url);

        return self::SUCCESS;
    }
}
