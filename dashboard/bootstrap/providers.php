<?php

declare(strict_types=1);

use App\Providers\AlertsServiceProvider;
use App\Providers\ApiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BackupsServiceProvider;
use App\Providers\ConfigAsCodeServiceProvider;
use App\Providers\DockerfileGenerationServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\GitServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\InteractiveSessionsServiceProvider;
use App\Providers\PreviewsServiceProvider;
use App\Providers\RemoteAccessServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\TransfersServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

return [
    AlertsServiceProvider::class,
    AppServiceProvider::class,
    ApiServiceProvider::class,
    BackupsServiceProvider::class,
    ConfigAsCodeServiceProvider::class,
    DockerfileGenerationServiceProvider::class,
    FortifyServiceProvider::class,
    GitServiceProvider::class,
    HorizonServiceProvider::class,
    InteractiveSessionsServiceProvider::class,
    PreviewsServiceProvider::class,
    RemoteAccessServiceProvider::class,
    SecurityServiceProvider::class,
    TransfersServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
];
