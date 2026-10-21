<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Actions\Audit\RecordAuditEvent;
use App\Data\Audit\AuditActor;
use App\Data\Audit\AuditEntryData;
use App\Enums\AuditAction;
use App\Models\InstanceSettings;
use App\Models\Registry\RegistryStorage;
use App\Services\Registry\ZotConfig;
use App\Services\Registry\ZotConfigFile;
use RuntimeException;

/**
 * Writes Zot's config.json (docs/14 §9.11) to `launchroom.registry.zot_config_path`
 * (the installer's data/zot/config.json, bind-mounted into the dashboard
 * container) when it differs from the rendered one, and records its hash
 * (instance_settings.registry_config_hash) for the settings page.
 * Restarting Zot is the caller's part (`./launchroom registry apply`).
 */
final readonly class WriteZotConfig
{
    public function __construct(
        private ZotConfig $config,
        private ZotConfigFile $file,
        private RecordAuditEvent $audit,
    ) {}

    /**
     * @return bool whether the file changed (Zot must be restarted)
     *
     * @throws RuntimeException
     */
    public function handle(AuditActor $actor): bool
    {
        $path = config('launchroom.registry.zot_config_path');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('LAUNCHROOM_ZOT_CONFIG_PATH is not set: this installation does not let the dashboard write the registry config.');
        }

        $storage = RegistryStorage::current();
        $contents = $this->config->json($storage);
        $hash = hash('sha256', $contents);
        $current = $this->file->hash($path);
        $changed = $current === null || ! hash_equals($current, $hash);

        if ($changed) {
            $this->file->write($path, $contents);
        }

        InstanceSettings::lockedForUpdate()->forceFill(['registry_config_hash' => $hash, 'registry_config_written_at' => now()])->save();

        if ($changed) {
            $this->audit->handle(new AuditEntryData(
                action: AuditAction::RegistryConfigWritten,
                actor: $actor,
                subjectType: 'instance',
                subjectId: 'registry',
                meta: ['storage' => $storage instanceof RegistryStorage ? 's3' : 'local', 'sha256' => substr($hash, 0, 16)],
            ));
        }

        return $changed;
    }
}
