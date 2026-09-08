<?php

declare(strict_types=1);

namespace App\Slate\Storage;

use Aws\Signature\S3SignatureV4;

final class ConditionalS3SignatureV4 extends S3SignatureV4
{
    /**
     * The stock signer excludes conditional headers, but a source upload must sign this condition so
     * omitting it invalidates the presigned URL instead of restoring ordinary overwrite semantics.
     *
     * @return array<string, bool>
     */
    protected function getHeaderBlacklist(): array
    {
        $headers = parent::getHeaderBlacklist();
        unset($headers['if-none-match']);

        return $headers;
    }
}
