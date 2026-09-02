<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use InvalidArgumentException;

final class FilterGraphCompiler
{
    /**
     * @param  list<string>  $operationFilters
     */
    public function compileH264Video(array $operationFilters): CompiledH264FilterGraph
    {
        return CompiledH264FilterGraph::simple($operationFilters);
    }

    /**
     * @param  list<string>  $prefixChains
     */
    public function compileH264ComplexVideo(array $prefixChains, string $terminalVideoChain): CompiledH264FilterGraph
    {
        return CompiledH264FilterGraph::complex($prefixChains, $terminalVideoChain);
    }

    public function compileDiagnosticDrawtext(string $fontPath): CompiledFilterGraph
    {
        return $this->compileDrawtext($fontPath, 'slate doctor');
    }

    public function compileDrawtext(string $fontPath, string $text): CompiledFilterGraph
    {
        return CompiledFilterGraph::fromCompiler('drawtext=fontfile='.$this->escapeOptionValue($fontPath)
            .':text='.$this->escapeOptionValue($text)
            .':expansion=none:fontcolor=white:fontsize=48:x=20:y=60');
    }

    public function escapeOptionValue(string $value): string
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new InvalidArgumentException('Drawtext option values cannot contain line breaks.');
        }

        $value = str_replace(['\\', "'", ':'], ['\\\\', "\\'", '\\:'], $value);

        return str_replace(
            ['\\', "'", ',', ';', '[', ']'],
            ['\\\\', "\\'", '\\,', '\\;', '\\[', '\\]'],
            $value,
        );
    }
}
