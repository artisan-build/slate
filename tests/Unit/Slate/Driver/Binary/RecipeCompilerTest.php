<?php

use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Caption\TimedWord;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\OperationName;

test('dispatches a typed timed-word operation to its one registered compiler', function (): void {
    $words = [TimedWord::fromValues("Mia's", 128, 720, 1.25, 0.2, 2.5, 3.0, 0.15)];
    $operation = new readonly class($words) implements Operation
    {
        /**
         * @param  list<TimedWord>  $words
         */
        public function __construct(public array $words) {}

        public function name(): OperationName
        {
            return OperationName::CaptionsTimedWords;
        }
    };
    $operationCompiler = new readonly class($operation::class) implements CompilesOperation
    {
        /**
         * @param  class-string<Operation>  $operationClass
         */
        public function __construct(private string $operationClass) {}

        public function supports(Operation $operation): bool
        {
            return $operation instanceof $this->operationClass;
        }

        public function compile(Operation $operation): CompiledRecipe
        {
            return new CompiledRecipe(['/runtime/ffmpeg', '-version']);
        }
    };

    $recipe = (new RecipeCompiler($operationCompiler))->compile($operation);

    expect($operation->words)->toBe($words)
        ->and($recipe->command())->toBe(['/runtime/ffmpeg', '-version']);
});

test('rejects an operation without an explicitly registered compiler', function (): void {
    $operation = new class implements Operation
    {
        public function name(): OperationName
        {
            return OperationName::Probe;
        }
    };

    expect(fn (): CompiledRecipe => (new RecipeCompiler)->compile($operation))
        ->toThrow(InvalidArgumentException::class, 'Operation [probe] is not supported');
});

test('rejects ambiguous compiler ownership for an operation', function (): void {
    $operation = new class implements Operation
    {
        public function name(): OperationName
        {
            return OperationName::Probe;
        }
    };
    $operationCompiler = new class implements CompilesOperation
    {
        public function supports(Operation $operation): bool
        {
            return true;
        }

        public function compile(Operation $operation): CompiledRecipe
        {
            return new CompiledRecipe(['/runtime/ffprobe', '-version']);
        }
    };

    expect(fn (): CompiledRecipe => (new RecipeCompiler($operationCompiler, $operationCompiler))->compile($operation))
        ->toThrow(LogicException::class, 'Multiple binary compilers support operation [probe]');
});
