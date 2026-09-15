<?php

declare(strict_types=1);

namespace App\Auth;

use ArtisanBuild\BuiltForCloud\Contracts\CredentialDeclaration;
use ArtisanBuild\BuiltForCloud\Contracts\DeclaresSelfServiceMintPolicy;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\Subject;
use Illuminate\Http\Request;

final class SlateCredentialDeclaration implements CredentialDeclaration, DeclaresSelfServiceMintPolicy
{
    public function resolveSubject(Request $request): ?Subject
    {
        return null;
    }

    public function authorize(Credential $credential, ?string $ability, Request $request): bool
    {
        return true;
    }

    public function selfServiceAbilities(Subject $subject): array
    {
        return [];
    }

    public function selfServiceKinds(Subject $subject): array
    {
        return [CredentialKind::Bearer];
    }
}
