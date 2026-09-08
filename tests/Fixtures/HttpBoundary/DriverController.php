<?php

use App\Slate\Driver\Driver;

final class DriverController
{
    public function __invoke(): void
    {
        resolve(Driver::class);
    }
}
