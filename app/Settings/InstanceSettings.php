<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InstanceSettings extends Settings
{
    public string $name = 'launchroom';

    public ?string $domain = null;

    public string $ipv4 = '';

    public string $ipv6 = '';

    public bool $registration_enabled = true;

    public static function group(): string
    {
        return 'default';
    }
}
