<?php

declare(strict_types=1);

namespace App\Slate\Source;

use App\Models\Source;
use App\Slate\Auth\AuthenticatedSubject;
use ArtisanBuild\BuiltForCloud\Subject;

final readonly class AuthenticatedSourceScope
{
    public function __construct(private AuthenticatedSubject $authenticatedSubject) {}

    public function subject(): Subject
    {
        return $this->authenticatedSubject->resolve();
    }

    public function find(string $sourceId): ?Source
    {
        return Source::ownedBy($this->subject())->find($sourceId);
    }
}
