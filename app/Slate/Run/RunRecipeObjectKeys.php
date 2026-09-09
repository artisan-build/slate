<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\SourceObjectKeys;
use InvalidArgumentException;

final class RunRecipeObjectKeys
{
    public static function fromStored(string $runPrefix, string $key): ObjectKey
    {
        try {
            return ObjectKey::withinPrefix($runPrefix, $key);
        } catch (InvalidArgumentException $prefixException) {
            try {
                return SourceObjectKeys::fromValue($key);
            } catch (InvalidArgumentException) {
                throw $prefixException;
            }
        }
    }
}
