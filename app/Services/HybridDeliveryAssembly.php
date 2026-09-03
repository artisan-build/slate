<?php

declare(strict_types=1);

namespace App\Services;

use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\ScratchPath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Assembles the hybrid delivery tier: each clip is stream-copied to its body, each junction is
 * rendered once as a re-encoded transition segment, and the pieces are spliced with copy-mode concat.
 *
 * The operation order returned by plan() is the splice order, so body and junction outputs interleave
 * by construction and the final concat consumes them in exactly that sequence.
 */
final readonly class HybridDeliveryAssembly
{
    public function __construct(
        private RecipeCompiler $compiler,
        private Driver $driver,
    ) {}

    /**
     * Validates the assembly and returns its operations in splice order. Pure: nothing is rendered.
     *
     * @param  array<array-key, mixed>  $inputs
     * @return list<VideoTrim|VideoTransitionSegment>
     */
    public function plan(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ScratchPath $output,
    ): array {
        $this->validate($inputs, $transitionFrameCount, $qualityTier);

        $lastIndex = count($inputs) - 1;
        $operations = [];

        foreach ($inputs as $index => $input) {
            $bodyStartFrame = $index === 0 ? 0 : $transitionFrameCount;
            $bodyEndFrame = $index === $lastIndex
                ? $input->frameCount
                : $input->frameCount - $transitionFrameCount;

            $operations[] = new VideoTrim(
                $input,
                $bodyStartFrame,
                $bodyEndFrame - $bodyStartFrame,
                $output->sibling(sprintf('hybrid-body-%02d.mp4', $index)),
            );

            if ($index < $lastIndex) {
                $operations[] = new VideoTransitionSegment(
                    $input,
                    $inputs[$index + 1],
                    $transitionFrameCount,
                    $qualityTier,
                    $output->sibling(sprintf('hybrid-junction-%02d.mp4', $index)),
                );
            }
        }

        return $operations;
    }

    /**
     * Renders the plan and splices it with copy-mode concat. Every executed operation is returned in
     * order alongside its measurement so callers can prove the stream-copy lever and report sizes.
     *
     * @param  non-empty-list<SlateVideo>  $inputs
     * @return array{output: SlateVideo, operations: non-empty-list<VideoTrim|VideoTransitionSegment|VideoConcat>, measurements: non-empty-list<array{operation: string, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int}>}
     */
    public function assemble(
        array $inputs,
        int $transitionFrameCount,
        QualityTier $qualityTier,
        ScratchPath $output,
    ): array {
        $operations = $this->plan($inputs, $transitionFrameCount, $qualityTier, $output);
        $splicedInputs = [];
        $measurements = [];
        $timedOperations = [];

        foreach ($operations as $operation) {
            $startedAtMs = $this->monotonicMilliseconds();
            $video = SlateVideo::render($operation, $this->compiler, $this->driver);
            $splicedInputs[] = $video;
            $timedOperations[] = $operation;
            $measurements[] = $this->measurement(
                $operation,
                $video,
                $this->monotonicMilliseconds() - $startedAtMs,
            );
        }

        $concat = new VideoConcat(
            $splicedInputs,
            VideoConcatMode::Copy,
            null,
            0.0,
            false,
            $qualityTier,
            $inputs[0]->framesPerSecond,
            $output,
        );
        $startedAtMs = $this->monotonicMilliseconds();
        $assembled = SlateVideo::render($concat, $this->compiler, $this->driver);
        $timedOperations[] = $concat;
        $measurements[] = $this->measurement(
            $concat,
            $assembled,
            $this->monotonicMilliseconds() - $startedAtMs,
        );

        return [
            'output' => $assembled,
            'operations' => $timedOperations,
            'measurements' => $measurements,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $inputs
     */
    private function validate(array $inputs, int $transitionFrameCount, QualityTier $qualityTier): void
    {
        if (count($inputs) < 2 || ! array_is_list($inputs)) {
            throw new InvalidArgumentException('Hybrid delivery assembly requires an ordered list of at least two Slate outputs.');
        }

        if ($transitionFrameCount < 1) {
            throw new InvalidArgumentException('Hybrid delivery transition must contain at least one frame.');
        }

        $framesPerSecond = $inputs[0]->framesPerSecond;
        $lastIndex = count($inputs) - 1;

        foreach ($inputs as $index => $input) {
            if ($input->width !== $inputs[0]->width || $input->height !== $inputs[0]->height) {
                throw new InvalidArgumentException('Hybrid delivery assembly inputs must have matching dimensions.');
            }

            if ($input->framesPerSecond !== $framesPerSecond) {
                throw new InvalidArgumentException('Hybrid delivery assembly inputs must have matching frame rates.');
            }

            if ($input->qualityTier !== $qualityTier) {
                throw new InvalidArgumentException('Hybrid delivery assembly requires the transition tier to match every input tier so the splice can stream-copy.');
            }

            $minimumFrameCount = $index === 0 || $index === $lastIndex
                ? $transitionFrameCount + 1
                : $transitionFrameCount * 2 + 1;

            if ($input->frameCount < $minimumFrameCount) {
                throw new InvalidArgumentException(sprintf(
                    'Hybrid delivery input %d of %d is too short: %d frames probed, %d required to keep a positive-length body after the junction overlap.',
                    $index + 1,
                    count($inputs),
                    $input->frameCount,
                    $minimumFrameCount,
                ));
            }

            if ($index > 0 && ($input->keyframeIntervalFrames === null
                    || $transitionFrameCount % $input->keyframeIntervalFrames !== 0)) {
                throw new InvalidArgumentException(sprintf(
                    'Hybrid delivery transition of %d frames must begin on the guaranteed keyframe grid of input %d.',
                    $transitionFrameCount,
                    $index + 1,
                ));
            }
        }
    }

    /**
     * @return array{operation: string, media_duration_ms: int, processing_duration_ms: int, output_size_bytes: int}
     */
    private function measurement(
        VideoTrim|VideoTransitionSegment|VideoConcat $operation,
        SlateVideo $video,
        int $processingDurationMs,
    ): array {
        return [
            'operation' => $operation->name()->value,
            'media_duration_ms' => (int) round($video->durationSeconds * 1000),
            'processing_duration_ms' => $processingDurationMs,
            'output_size_bytes' => $this->fileSize($video->path->readablePath()),
        ];
    }

    private function fileSize(string $path): int
    {
        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException("Unable to measure file size: {$path}");
        }

        return $size;
    }

    private function monotonicMilliseconds(): int
    {
        return intdiv((int) hrtime(true), 1_000_000);
    }
}
