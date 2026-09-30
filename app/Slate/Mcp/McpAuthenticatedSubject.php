<?php

declare(strict_types=1);

namespace App\Slate\Mcp;

use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final readonly class McpAuthenticatedSubject
{
    public function __construct(private Request $request) {}

    public function resolve(): Subject
    {
        $delegated = RequestAssertion::principal($this->request)?->delegatedActor;

        if ($delegated instanceof DelegatedActor) {
            return new Subject(
                SubjectType::ExternalConsumer,
                DelegatedActor::class.'#'.$delegated->getAuthIdentifier(),
            );
        }

        $credential = $this->request->user();

        if (! $credential instanceof Credential) {
            throw new AuthenticationException;
        }

        return $credential->subject();
    }
}
