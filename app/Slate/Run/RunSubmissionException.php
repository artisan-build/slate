<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Input\InputRejectionReason;
use InvalidArgumentException;

final class RunSubmissionException extends InvalidArgumentException
{
    public function __construct(
        public readonly RunApiErrorReason|InputRejectionReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
