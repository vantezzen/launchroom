<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // health: '/up',
        health: '/healthz',
    )
    ->create();
