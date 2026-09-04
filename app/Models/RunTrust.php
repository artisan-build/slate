<?php

namespace App\Models;

enum RunTrust: string
{
    case TrustedOrigin = 'trusted-origin';
    case Untrusted = 'untrusted';
}
