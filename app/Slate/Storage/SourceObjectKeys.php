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

    public static function fromValue(string $key): ObjectKey
    {
        if (preg_match('~^slate-sources/([0-9a-fA-F-]{36})/source$~', $key, $matches) !== 1) {
            throw new InvalidArgumentException('Source object key must use the server-derived source path.');
        }

        $objectKey = self::object($matches[1]);

        if ($objectKey->value() !== $key) {
            throw new InvalidArgumentException('Source object key must use the server-derived source path.');
        }

        return $objectKey;
    }
}
