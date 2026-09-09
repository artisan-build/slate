<?php

namespace App\Models;

use App\Slate\Http\WireEnum;

enum RunTrust: string implements WireEnum
{
    case TrustedOrigin = 'trusted-origin';
    case Untrusted = 'untrusted';
}
