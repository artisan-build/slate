<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class RunObjectKeys
{
    public static function prefix(string $runId): string
    {
        if (! Str::isUuid($runId)) {
            throw new InvalidArgumentException('Run object keys require a UUID run ID.');
        }

        return 'slate-runs/'.$runId;
    }

    public static function operationOutput(string $runId, int $operationIndex): ObjectKey
    {
        return self::operationKey($runId, $operationIndex, 'output.mp4');
    }

    public static function subClip(string $runId, int $operationIndex, int $subIndex): ObjectKey
    {
        if ($subIndex < 0) {
            throw new InvalidArgumentException('Run sub-clip index cannot be negative.');
        }

        return self::operationKey($runId, $operationIndex, sprintf('subclips/%04d.mp4', $subIndex));
    }

    public static function silentConcat(string $runId, int $operationIndex): ObjectKey
    {
        return self::operationKey($runId, $operationIndex, 'silent.mp4');
    }

    private static function operationKey(string $runId, int $operationIndex, string $suffix): ObjectKey
    {
        if ($operationIndex < 0) {
            throw new InvalidArgumentException('Run operation index cannot be negative.');
        }

        $prefix = self::prefix($runId);

        return ObjectKey::withinPrefix(
            $prefix,
            sprintf('%s/work/operations/%04d/%s', $prefix, $operationIndex, $suffix),
        );
    }
}
