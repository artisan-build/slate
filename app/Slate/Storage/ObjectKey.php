<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use InvalidArgumentException;

final readonly class ObjectKey
{
    private function __construct(private string $value) {}

    /**
     * @param  string  $prefix  Server-derived ownership scope, never a value paired with the key by the caller.
     */
    public static function withinPrefix(string $prefix, string $key): self
    {
        self::assertValidPath($prefix, 'Object prefix');
        self::assertValidPath($key, 'Object key');

        if (! str_starts_with($key, $prefix.'/')) {
            throw new InvalidArgumentException('Object key must remain within the caller prefix.');
        }

        return new self($key);
    }

    public function value(): string
    {
        return $this->value;
    }

    private static function assertValidPath(string $path, string $label): void
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            throw new InvalidArgumentException("{$label} must be a relative object path.");
        }

        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException("{$label} contains unsupported characters.");
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("{$label} contains an invalid path segment.");
            }
        }
    }
}
