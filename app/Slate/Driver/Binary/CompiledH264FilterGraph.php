<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use InvalidArgumentException;

/** A normalized video graph that must be supplied before H.264 output argv can be compiled. */
final readonly class CompiledH264FilterGraph
{
    /**
     * @param  non-empty-list<string>  $operationFilters
     */
    public static function simple(array $operationFilters): self
    {
        if ($operationFilters === [] || ! array_is_list($operationFilters)) {
            throw new InvalidArgumentException('A simple H.264 graph requires an ordered operation filter chain.');
        }

        return new self(CompiledFilterGraph::fromCompiler(implode(',', [
            ...$operationFilters,
            H264OutputContract::videoNormalizationFilter(),
        ])), false);
    }

    /**
     * @param  list<string>  $prefixChains
     */
    public static function complex(array $prefixChains, string $terminalVideoChain): self
    {
        if (! array_is_list($prefixChains) || $terminalVideoChain === '') {
            throw new InvalidArgumentException('A complex H.264 graph requires ordered chains and one terminal video chain.');
        }

        return new self(CompiledFilterGraph::fromCompiler(implode(';', [
            ...$prefixChains,
            $terminalVideoChain.'[operation_video]',
            '[operation_video]'.H264OutputContract::videoNormalizationFilter().'[video]',
        ])), true);
    }

    private function __construct(
        private CompiledFilterGraph $filterGraph,
        public bool $complex,
    ) {}

    public function filterGraph(): CompiledFilterGraph
    {
        return $this->filterGraph;
    }
}
