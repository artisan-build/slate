<?php

declare(strict_types=1);

namespace App\Slate\Recipe;

/**
 * Implementations must carry only typed domain parameters. Expression-valued drawtext options such as
 * x, y, enable, and alpha have no public or compiler-side string seam yet; their future compiler must
 * derive expressions exclusively from typed coordinates, timings, and closed easing enums.
 */
interface Operation
{
    public function name(): OperationName;
}
