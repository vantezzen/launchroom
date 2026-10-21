<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\Registry\RegistrySettingsData;
use App\Data\Registry\RegistryStorageData;
use App\Models\InstanceSettings;
use App\Models\Registry\RegistryStorage;
use App\Services\Crypto\Exceptions\DecryptionFailed;
use App\Services\Registry\ZotConfig;

/**
 * Settings → Registry (docs/14 §9.11): the S3 storage (no secrets) and
 * whether the config Zot runs with matches it. `applied` is null while the
 * installer's local-storage template is in use and nothing was configured
 * here; false when the saved settings still need `./launchroom registry apply`.
 */
final readonly class RegistrySettings
{
    public const string APPLY_COMMAND = './launchroom registry apply';

    public function __construct(
        private ZotConfig $config,
    ) {}

    public function get(): RegistrySettingsData
    {
        $storage = RegistryStorage::current();
        $settings = InstanceSettings::current();

        return new RegistrySettingsData(
            storage: $storage instanceof RegistryStorage ? RegistryStorageData::fromStorage($storage) : null,
            applied: $this->applied($storage, $settings->registry_config_hash),
            writtenAt: $settings->registry_config_written_at,
            applyCommand: self::APPLY_COMMAND,
        );
    }

    private function applied(?RegistryStorage $storage, ?string $written): ?bool
    {
        if ($written === null) {
            return $storage instanceof RegistryStorage ? false : null;
        }

        try {
            return hash_equals($written, hash('sha256', $this->config->json($storage)));
        } catch (DecryptionFailed) {
            return false;
        }
    }
}
