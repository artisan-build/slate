<?php

use App\Slate\Driver\Binary\CompiledH264OutputOptions;
use App\Slate\Driver\Binary\CompiledOutput;

$options = CompiledH264OutputOptions::concat();
$output = CompiledOutput::jpeg($callerPath);
