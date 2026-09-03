<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary\Operation;

use App\Slate\Driver\Binary\BinaryExecutable;
use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledH264OutputOptions;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledLiteral;
use App\Slate\Driver\Binary\CompiledOption;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\CompilesOperation;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\FilterGraphCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use InvalidArgumentException;

final readonly class VideoTransitionSegmentCompiler implements CompilesOperation
{
    public function __construct(
        private FfmpegLocator $locator,
        private FilterGraphCompiler $filterGraphCompiler,
    ) {}

    public function supports(Operation $operation): bool
    {
        return $operation instanceof VideoTransitionSegment;
    }

    public function compile(Operation $operation): CompiledRecipe
    {
        if (! $operation instanceof VideoTransitionSegment) {
            throw new InvalidArgumentException('VideoTransitionSegmentCompiler can compile only VideoTransitionSegment operations.');
        }

        $duration = $this->formatSeconds($operation->durationSeconds());
        $filterGraph = $this->filterGraphCompiler->compileH264ComplexVideo([
            "[0:v]trim=end_frame={$operation->frameCount},setpts=PTS-STARTPTS[from_video]",
            "[1:v]trim=end_frame={$operation->frameCount},setpts=PTS-STARTPTS[to_video]",
            "[0:a]atrim=duration={$duration},asetpts=PTS-STARTPTS[from_audio]",
            "[1:a]atrim=duration={$duration},asetpts=PTS-STARTPTS[to_audio]",
            "[from_audio][to_audio]acrossfade=d={$duration}[audio]",
        ], "[from_video][to_video]xfade=transition=fade:duration={$duration}:offset=0");
        $seek = $operation->fromStartFrame() === 0
            ? CompiledArgument::literal(CompiledLiteral::Zero)
            : CompiledArgument::seconds($operation->fromStartSeconds());

        return new CompiledRecipe($this->locator, BinaryExecutable::Ffmpeg, [
            CompiledArgument::option(CompiledOption::Overwrite),
            CompiledArgument::option(CompiledOption::Seek),
            $seek,
            CompiledInput::ffmpeg($operation->from->path),
            CompiledInput::ffmpeg($operation->to->path),
            CompiledOutput::h264(
                $filterGraph,
                $operation->qualityTier,
                $operation->from->width,
                $operation->from->height,
                $operation->framesPerSecond(),
                CompiledH264OutputOptions::transition($operation->durationSeconds(), $operation->frameCount),
                $operation->output,
            ),
        ]);
    }

    private function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 6, '.', ''), '0'), '.') ?: '0';
    }
}
