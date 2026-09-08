<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class SourceObjectKeys
{
    public static function prefix(string $sourceId): string
    {
        if (! Str::isUuid($sourceId)) {
            throw new InvalidArgumentException('Source object keys require a UUID source ID.');
        }

        return 'slate-sources/'.$sourceId;
    }

    public static function object(string $sourceId): ObjectKey
    {
        $prefix = self::prefix($sourceId);

        return ObjectKey::withinPrefix($prefix, $prefix.'/source');
    }
}
