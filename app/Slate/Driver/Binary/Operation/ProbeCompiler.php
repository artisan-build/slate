<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Probe;
use InvalidArgumentException;

final readonly class ProbeCompiler implements CompilesOperation
{
    public function __construct(private FfmpegLocator $locator) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof Probe;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof Probe) {
            throw new InvalidArgumentException('ProbeCompiler can compile only Probe operations.');
        }

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffprobe, [
            CompiledArgument::option(CompiledOption::Verbosity),
            CompiledArgument::literal(CompiledLiteral::Error),
            CompiledArgument::option(CompiledOption::ShowFormat),
            CompiledArgument::option(CompiledOption::ShowStreams),
            CompiledArgument::option(CompiledOption::OutputFormat),
            CompiledArgument::literal(CompiledLiteral::Json),
            CompiledInput::ffprobe($operation->input),
        ]);
    }
}
