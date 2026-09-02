<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Driver\Binary\Operation\AudioConcatCompiler;
use App\Slate\Driver\Binary\Operation\AudioPadCompiler;
use App\Slate\Driver\Binary\Operation\AudioSilenceCompiler;
use App\Slate\Driver\Binary\Operation\ProbeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use InvalidArgumentException;
use LogicException;

final readonly class RecipeCompiler
{
    /**
     * @var list<CompilesOperation>
     */
    private array $compilers;

    public function __construct(CompilesOperation ...$compilers)
    {
        $this->compilers = $compilers;
    }

    public static function fromSystem(): self
    {
        $locator = FfmpegLocator::fromSystem();

        return new self(
            new ProbeCompiler($locator),
            new AudioSilenceCompiler($locator),
            new AudioConcatCompiler($locator),
            new AudioPadCompiler($locator),
        );
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        $matchingCompiler = null;

        foreach ($this->compilers as $compiler) {
            if (! $compiler->supports($operation)) {
                continue;
            }

            if ($matchingCompiler instanceof CompilesOperation) {
                throw new LogicException("Multiple binary compilers support operation [{$operation->name()->value}].");
            }

            $matchingCompiler = $compiler;
        }

        if (! $matchingCompiler instanceof CompilesOperation) {
            throw new InvalidArgumentException("Operation [{$operation->name()->value}] is not supported by this compiler.");
        }

        return $matchingCompiler->compile($operation);
    }
}
