<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

enum LavfiInput: string
{
    case BlackDiagnosticFrame = 'color=c=black:s=640x200:d=1';
    case Silence44100Mono = 'anullsrc=r=44100:cl=mono';
    case Silence44100Stereo = 'anullsrc=r=44100:cl=stereo';
}
