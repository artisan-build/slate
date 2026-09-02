<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Recipe\Caption\TimedWords;
use InvalidArgumentException;

final class FilterGraphCompiler
{
    private const int MaxFilterGraphBytes = 120000;

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
        return CompiledFilterGraph::fromCompiler($this->drawtext(
            $fontPath,
            $text,
            CompiledDrawtextOptions::diagnostic(),
        ));
    }

    public function compileTimedWords(TimedWords $operation): CompiledH264FilterGraph
    {
        $fontPath = $operation->font->readablePath();
        $filters = [];

        foreach ($operation->words as $word) {
            $filters[] = $this->drawtext(
                $fontPath,
                $word->text,
                CompiledDrawtextOptions::timed(
                    $word,
                    $operation->fontSize,
                    $operation->color,
                    $operation->shadow,
                    $operation->entryEase,
                    $operation->exitEase,
                    $operation->dim,
                ),
            );
        }

        if (strlen(implode(',', $filters)) > self::MaxFilterGraphBytes) {
            throw new InvalidArgumentException('Compiled caption filtergraph exceeds the safe process argument size.');
        }

        return $this->compileH264Video($filters);
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

    private function drawtext(string $fontPath, string $text, CompiledDrawtextOptions $options): string
    {
        return 'drawtext=fontfile='.$this->escapeOptionValue($fontPath)
            .':text='.$this->escapeOptionValue($text)
            .':expansion=none:'.$options->value();
    }
}
