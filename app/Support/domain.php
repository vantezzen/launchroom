<?php

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

    return explode(':', $_SERVER['HTTP_HOST'])[0];
}
