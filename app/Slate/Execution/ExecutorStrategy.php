<?php

namespace App\Slate\Execution;

enum ExecutorStrategy: string
{
    case SingleJob = 'single_job';
    case Fanout = 'fanout';
    case SubOperationSplit = 'sub_operation_split';
}
