<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function getUniqueSlug(string $name, string $table): string
{
    $slug = Str::slug($name);
    $fullSlug = $slug;

    while (DB::table($table)->where('slug', $fullSlug)->count() > 0) {
        $fullSlug = $slug.'-'.Str::random(5);
    }

    return $fullSlug;
}
