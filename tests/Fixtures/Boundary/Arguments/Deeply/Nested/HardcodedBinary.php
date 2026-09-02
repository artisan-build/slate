<?php

use Illuminate\Support\Facades\Process;

Process::run(['/var/www/html/runtime/ffmpeg/bin/ffmpeg', '-i', $callerInput]);
