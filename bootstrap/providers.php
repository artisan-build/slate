<?php

use App\Providers\AppServiceProvider;
use App\Slate\Driver\DriverServiceProvider;
use App\Slate\Storage\StorageServiceProvider;

return [
    AppServiceProvider::class,
    DriverServiceProvider::class,
    StorageServiceProvider::class,
];
