<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator;
use ArtisanBuild\BuiltForCloud\Auth\CredentialGuard;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateSlateSources
{
    public function __construct(
        private BearerAuthenticator $bearerAuthenticator,
        private AppPurposeRegistry $purposeRegistry,
        private AuthManager $auth,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if (! is_string($authorization) || preg_match('/^Bearer [^\s]+$/D', $authorization) !== 1) {
            throw new AuthenticationException;
        }

        $credential = $this->bearerAuthenticator->credential($request);
        $purpose = $this->purposeRegistry->purpose('slate.sources');
        $installationSubjectRef = config('slate.api.installation_subject_ref');
        $isAccountCredential = $credential?->purpose === CredentialPurpose::Consumption
            && $credential->user_id !== null
            && $credential->subject_type === SubjectType::UserPrincipal;
        $isInstallationCredential = $credential?->purpose === CredentialPurpose::Consumption
            && $credential->user_id === null
            && $credential->subject_type === SubjectType::Installation
            && is_string($installationSubjectRef)
            && $installationSubjectRef !== ''
            && hash_equals($installationSubjectRef, $credential->subject_ref);

        if ($purpose !== CredentialPurpose::Consumption || (! $isAccountCredential && ! $isInstallationCredential)) {
            throw new AuthenticationException;
        }

        $guard = $this->auth->guard((string) config('built-for-cloud.credentials.guard', 'bfc'));

        if (! $guard instanceof CredentialGuard) {
            throw new LogicException('The Slate Sources credential guard must use Built for Cloud.');
        }

        if ($guard->credentialForPurposes([$purpose]) === null) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
