<?php

declare(strict_types=1);

namespace App\Slate\Mcp;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Auth\AuthenticationException;

final readonly class McpAuthenticatedSubject
{
    public function resolve(ActingPrincipal $actingPrincipal): Subject
    {
        $delegated = $actingPrincipal->delegatedActor;

        if ($actingPrincipal->delegated && $delegated instanceof DelegatedActor) {
            return new Subject(
                SubjectType::ExternalConsumer,
                DelegatedActor::class.'#'.$delegated->getAuthIdentifier(),
            );
        }

        $credential = $actingPrincipal->principal;

        if (! $credential instanceof Credential) {
            throw new AuthenticationException;
        }

        return $credential->subject();
    }
}
