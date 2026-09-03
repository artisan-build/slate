<?php

declare(strict_types=1);

namespace App\Services;

enum BenchmarkStrategy: string
{
    case Both = 'both';
    case Xfade = 'xfade';
    case Hybrid = 'hybrid';

    public function includesXfade(): bool
    {
        return $this !== self::Hybrid;
    }

    public function includesHybrid(): bool
    {
        return $this !== self::Xfade;
    }

    public static function fromOptionValue(?string $value): ?self
    {
        return self::tryFrom($value ?? self::Both->value);
    }
}
