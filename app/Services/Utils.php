<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Utils
{
    public static function getUniqueSlug(string $name, string $table): string
    {
        $slug = Str::slug($name);
        $fullSlug = $slug;

        while (DB::table($table)->where('slug', $fullSlug)->count() > 0) {
            $fullSlug = $slug.'-'.Str::random(5);
        }

        return $fullSlug;
    }

    public static function getCloudDomain(string $slug, string $environment): string
    {
        $baseIp = self::baseIp();

        return "$slug-$environment.$baseIp.sslip.io";
    }

    public static function baseIp()
    {
        if (config('app.env') === 'local') {
            return '127.0.0.1';
        }

        return explode(':', $_SERVER['HTTP_HOST'])[0];
    }
}
