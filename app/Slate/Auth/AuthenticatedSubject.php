<?php

declare(strict_types=1);

namespace App\Slate\Auth;

use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\Subject;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use LogicException;

final readonly class AuthenticatedSubject
{
    public function __construct(private AuthManager $auth) {}

    public function resolve(): Subject
    {
        $guardName = (string) config('built-for-cloud.credentials.guard', 'bfc');
        $guard = $this->auth->guard($guardName);

        if (! $guard instanceof CredentialGuard) {
            throw new LogicException("The [{$guardName}] guard is not a Built for Cloud credential guard.");
        }

        $credential = $guard->credential();

        if ($credential === null) {
            throw new AuthenticationException;
        }

        return $credential->subject();
    }
}
