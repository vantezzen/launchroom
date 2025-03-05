<?php
namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class Utils
{
  public static function getUniqueSlug(string $name, string $table): string
  {
    $slug = Str::slug($name);
    $fullSlug = $slug;

    while(DB::table($table)->where('slug', $fullSlug)->count() > 0) {
      $fullSlug = $slug . '-' . Str::random(5);
    }
    return $fullSlug;
  }
}