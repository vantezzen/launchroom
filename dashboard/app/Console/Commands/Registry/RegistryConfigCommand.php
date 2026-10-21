<?php

declare(strict_types=1);

namespace App\Console\Commands\Registry;

use App\Actions\Registry\WriteZotConfig;
use App\Data\Audit\AuditActor;
use App\Models\Registry\RegistryStorage;
use App\Services\Registry\ZotConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Zot's config.json from the dashboard's registry settings (docs/14 §9.11).
 *
 *   launchroom:registry:config           print it with the S3 credentials redacted
 *   launchroom:registry:config --write   write it to LAUNCHROOM_ZOT_CONFIG_PATH;
 *                                        prints "changed" (restart Zot) or "unchanged"
 *
 * `./launchroom registry apply` runs the second form and restarts Zot when
 * it printed "changed"; the installer does the same after every install.
 */
#[Signature('launchroom:registry:config {--write : Write the config file (when it differs) instead of printing it}')]
#[Description('Print or write the registry (Zot) config rendered from the registry settings')]
final class RegistryConfigCommand extends Command
{
    public function handle(ZotConfig $config, WriteZotConfig $write): int
    {
        if ($this->option('write') !== true) {
            $this->output->write($config->redacted(RegistryStorage::current()));

            return self::SUCCESS;
        }

        try {
            $this->line($write->handle(AuditActor::system()) ? 'changed' : 'unchanged');
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
