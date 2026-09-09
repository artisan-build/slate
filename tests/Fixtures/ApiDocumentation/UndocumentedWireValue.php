<?php

declare(strict_types=1);

namespace Tests\Fixtures\ApiDocumentation;

use App\Slate\Http\WireEnum;

enum UndocumentedWireValue: string implements WireEnum
{
    case PositiveControl = 'documentation_positive_control';
}
