<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Models\Run;
use App\Slate\Auth\AuthenticatedSubject;
use ArtisanBuild\BuiltForCloud\Subject;

final readonly class AuthenticatedRunScope
{
    public function __construct(private AuthenticatedSubject $authenticatedSubject) {}

    public function subject(): Subject
    {
        return $this->authenticatedSubject->resolve();
    }

    public function find(string $runId): ?Run
    {
        return Run::ownedBy($this->subject())->find($runId);
    }
}
