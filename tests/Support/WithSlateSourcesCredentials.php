<?php

declare(strict_types=1);

namespace Tests\Support;

use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;

trait WithSlateSourcesCredentials
{
    use WithCredentials {
        mintCredential as private mintBuiltForCloudCredential;
    }

    /** @param array<string, mixed> $attributes */
    protected function mintCredential(array $attributes = []): MintedTestCredential
    {
        $subjectRef = $attributes['subject_ref'] ?? 'test-subject';
        config()->set('slate.api.installation_subject_ref', $subjectRef);

        return $this->mintBuiltForCloudCredential(array_merge([
            'kind' => CredentialKind::Bearer,
            'purpose' => CredentialPurpose::Consumption,
            'subject_type' => SubjectType::Installation,
            'subject_ref' => $subjectRef,
            'user_id' => null,
        ], $attributes));
    }

    protected function mintNonSlateCredential(): MintedTestCredential
    {
        return $this->mintBuiltForCloudCredential();
    }
}
