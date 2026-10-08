<?php

declare(strict_types=1);

namespace App\Slate\Auth;

/**
 * The reference an installation credential must carry to authenticate against this installation.
 *
 * `SLATE_INSTALLATION_SUBJECT_REF` overrides it. Left unset — the state every fresh install starts
 * in — the reference is derived from the application URL's host, which is distinct per installation,
 * already known to whoever deployed it, and survives a database restored into another environment.
 */
final readonly class InstallationReference
{
    public const string FALLBACK = 'localhost';

    public static function current(): string
    {
        $configured = config('slate.api.installation_subject_ref');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return self::fromApplicationUrl(config('app.url'));
    }

    public static function fromApplicationUrl(mixed $url): string
    {
        $candidate = is_string($url) ? trim($url) : '';
        $host = parse_url($candidate, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = parse_url('//'.ltrim($candidate, '/'), PHP_URL_HOST);
        }

        return is_string($host) && $host !== ''
            ? mb_strtolower($host)
            : self::FALLBACK;
    }
}
