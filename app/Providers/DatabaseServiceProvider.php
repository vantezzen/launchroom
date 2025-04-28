<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Blueprint::macro('foreignHashIdFor', function ($model, $column = null, $length = null) {
            $instance = new $model;
            $column = $column ?: $instance->getForeignKey();
            $length = $length ?: 255;
            
            $this->string($column, $length);
            
            return $this->foreign($column)
                ->references($instance->getKeyName())
                ->on($instance->getTable());
        });
    }
}
