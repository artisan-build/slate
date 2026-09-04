<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Models\Run;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\Subject;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use LogicException;

final readonly class AuthenticatedRunScope
{
    public function __construct(private AuthManager $auth) {}

    public function subject(): Subject
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

    public function find(string $runId): ?Run
    {
        return Run::ownedBy($this->subject())->find($runId);
    }
}
