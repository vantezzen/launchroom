<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InstanceSettings extends Settings
{
    public string $name = 'launchroom';

    public ?string $domain = null;

    public bool $publicly_accessible = true;

    public string $ipv4 = '';

    public ?string $ipv6 = '';

    public bool $setup_done = false;

    public ?string $admin_email = null;

    public static function group(): string
    {
        return 'default';
    }
}
