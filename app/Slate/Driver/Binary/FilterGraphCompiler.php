<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use InvalidArgumentException;

final class FilterGraphCompiler
{
    /**
     * @param  list<string>  $operationFilters
     */
    public function compileH264Video(array $operationFilters): string
    {
        return implode(',', [
            ...$operationFilters,
            H264OutputContract::videoNormalizationFilter(),
        ]);
    }

    public function compileDiagnosticDrawtext(string $fontPath): string
    {
        return $this->compileDrawtext($fontPath, 'slate doctor');
    }

    public function compileDrawtext(string $fontPath, string $text): string
    {
        return 'drawtext=fontfile='.$this->escapeOptionValue($fontPath)
            .':text='.$this->escapeOptionValue($text)
            .':expansion=none:fontcolor=white:fontsize=48:x=20:y=60';
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
