<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

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
        return "drawtext=fontfile='{$this->escapeOptionValue($fontPath)}':text='slate doctor':fontcolor=white:fontsize=48:x=20:y=60";
    }

    private function escapeOptionValue(string $value): string
    {
        return str_replace(['\\', ':', "'"], ['\\\\', '\\:', "\\'"], $value);
    }
}
