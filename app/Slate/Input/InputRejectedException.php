<?php

declare(strict_types=1);

namespace App\Slate\Input;

use RuntimeException;

final class InputRejectedException extends RuntimeException
{
    public function __construct(
        public readonly InputRejectionReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
