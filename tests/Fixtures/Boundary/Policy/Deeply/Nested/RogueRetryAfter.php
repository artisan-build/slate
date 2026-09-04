<?php

final class RogueRetryAfter
{
    public function window(): int
    {
        $retryAfter = 240;

        return $retryAfter;
    }
}
