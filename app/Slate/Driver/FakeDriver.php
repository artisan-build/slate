<?php

declare(strict_types=1);

namespace App\Slate\Driver;

final class FakeDriver implements Driver
{
    /**
     * @var list<CompiledRecipe>
     */
    private array $recipes = [];

    /**
     * @var list<DriverResult>
     */
    private array $results;

    private int $resultIndex = 0;

    public function __construct(DriverResult ...$results)
    {
        $this->results = $results;
    }

    public function run(CompiledRecipe $recipe): DriverResult
    {
        $this->recipes[] = $recipe;

        return $this->results[$this->resultIndex++] ?? new DriverResult;
    }

    /**
     * @return list<CompiledRecipe>
     */
    public function recipes(): array
    {
        return $this->recipes;
    }
}
