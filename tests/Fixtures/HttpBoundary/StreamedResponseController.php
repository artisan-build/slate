<?php

use Symfony\Component\HttpFoundation\StreamedResponse;

final class StreamedResponseController
{
    public function __invoke(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {});
    }
}
