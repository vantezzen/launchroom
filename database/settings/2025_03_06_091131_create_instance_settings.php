<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('instance.name', 'launchroom');
        $this->migrator->add('instance.domain', '');
        $this->migrator->add('instance.ipv4', '');
        $this->migrator->add('instance.ipv6', '');
        $this->migrator->add('instance.registration_enabled', true);
    }
};
