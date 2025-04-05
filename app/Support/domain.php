<?php

use App\Settings\InstanceSettings;

function getCloudDomain(string $slug, string $environment): string
{
    $baseIp = baseIp();

    return "$slug-$environment.$baseIp.sslip.io";
}

function baseIp()
{
    if (config('app.env') === 'local') {
        return '127.0.0.1';
    }

    $settings = app(InstanceSettings::class);

    return $settings->ipv4;
}
