<?php

use App\Services\FfmpegDiagnostics;
use App\Services\HybridDeliveryAssembly;
use App\Slate\Driver\Binary\BenchmarkOutputVerifier;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Driver\FakeDriver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoConcatMode;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;

function hybridAssemblySourceClip(ScratchDirectory $scratch, string $name, float $durationSeconds): VideoStillClip
{
    return new VideoStillClip(
        $scratch->file($name.'.png'),
        $scratch->file($name.'.mp3'),
        $scratch->file($name.'.mp4'),
        1536,
        1024,
        24,
        $durationSeconds,
        VideoFit::ContainPad,
        VideoColor::Black,
        new StillMotion,
        1,
        QualityTier::Delivery,
        AudioBitrate::Kbps192,
    );
}

/**
 * Renders dummy files for render recipes and returns queued probe properties for probe recipes,
 * keyed by basename so the plan's interleaved step outputs resolve in execution order.
 *
 * @param  array<string, array<string, mixed>>  $propertiesByFile
 */
function hybridAssemblyFakeDriver(array $propertiesByFile): Driver
{
    return new class($propertiesByFile) implements Driver
    {
        /** @param array<string, array<string, mixed>> $propertiesByFile */
        public function __construct(private readonly array $propertiesByFile) {}

        public function run(CompiledRecipe $recipe): DriverResult
        {
            $command = $recipe->command();
            $isProbe = in_array('-show_format', $command, true);

            foreach ($command as $argument) {
                $properties = $this->propertiesByFile[basename($argument)] ?? null;

                if ($properties === null) {
                    continue;
                }

                if ($isProbe) {
                    return new DriverResult(json_encode($properties, JSON_THROW_ON_ERROR));
                }

                file_put_contents($argument, 'rendered by fake driver');

                return new DriverResult;
            }

            throw new RuntimeException('Fake hybrid assembly driver received an unexpected recipe: '.implode(' ', $command));
        }
    };
}

/**
 * Probe properties for every step of an assembly over the supplied input frame counts, keyed by the
 * basename the plan assigns to each step output.
 *
 * @param  list<int>  $inputFrameCounts
 * @return array<string, array<string, mixed>>
 */
function hybridAssemblyStepProperties(int ...$inputFrameCounts): array
{
    $properties = [];
    $lastIndex = count($inputFrameCounts) - 1;

    $videoStream = static fn (int $frameCount): array => [
        'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1536, 'height' => 1024,
        'avg_frame_rate' => '24/1', 'pix_fmt' => 'yuv420p', 'color_range' => 'tv',
        'nb_frames' => (string) $frameCount,
    ];

    foreach ($inputFrameCounts as $index => $inputFrameCount) {
        $bodyFrameCount = $index === 0 || $index === $lastIndex
            ? $inputFrameCount - 24
            : $inputFrameCount - 48;

        $properties[sprintf('hybrid-body-%02d.mp4', $index)] = [
            'streams' => [$videoStream($bodyFrameCount), ['codec_type' => 'audio', 'codec_name' => 'aac']],
            'format' => ['duration' => number_format($bodyFrameCount / 24, 6, '.', '')],
        ];

        if ($index < $lastIndex) {
            $properties[sprintf('hybrid-junction-%02d.mp4', $index)] = [
                'streams' => [$videoStream(24), ['codec_type' => 'audio', 'codec_name' => 'aac']],
                'format' => ['duration' => '1.000000'],
            ];
        }
    }

    $totalFrames = array_sum($inputFrameCounts) - 24 * (count($inputFrameCounts) - 1);
    $properties['assembled.mp4'] = [
        'streams' => [$videoStream($totalFrames), ['codec_type' => 'audio', 'codec_name' => 'aac']],
        'format' => ['duration' => number_format($totalFrames / 24, 6, '.', '')],
    ];

    return $properties;
}

test('plans body trims and junction segments in splice order', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $inputs = [
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'first', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'second', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'third', 3.0)),
        ];
        $assembly = new HybridDeliveryAssembly(RecipeCompiler::fromSystem(), new FakeDriver);
        $plan = $assembly->plan($inputs, 24, QualityTier::Delivery, $scratch->file('assembled.mp4'));

        expect($plan)->toHaveCount(5);

        [$firstBody, $firstJunction, $middleBody, $secondJunction, $lastBody] = $plan;

        expect($firstBody)->toBeInstanceOf(VideoTrim::class)
            ->and($firstBody->startFrame)->toBe(0)
            ->and($firstBody->frameCount)->toBe(48)
            ->and($firstJunction)->toBeInstanceOf(VideoTransitionSegment::class)
            ->and($firstJunction->frameCount)->toBe(24)
            ->and($firstJunction->fromStartFrame())->toBe(48)
            ->and($middleBody)->toBeInstanceOf(VideoTrim::class)
            ->and($middleBody->startFrame)->toBe(24)
            ->and($middleBody->frameCount)->toBe(24)
            ->and($secondJunction)->toBeInstanceOf(VideoTransitionSegment::class)
            ->and($secondJunction->frameCount)->toBe(24)
            ->and($lastBody)->toBeInstanceOf(VideoTrim::class)
            ->and($lastBody->startFrame)->toBe(24)
            ->and($lastBody->frameCount)->toBe(48)
            ->and($lastBody->output->writablePath())->toBe($scratch->file('hybrid-body-02.mp4')->writablePath());
    });
});

test('assembles the plan through the driver as measured stream-copy steps and a copy concat', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $inputs = [
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'first', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'second', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'third', 3.0)),
        ];
        $compiler = RecipeCompiler::fromSystem();
        $driver = hybridAssemblyFakeDriver(hybridAssemblyStepProperties(72, 72, 72));
        $assembly = new HybridDeliveryAssembly($compiler, $driver);
        $result = $assembly->assemble($inputs, 24, QualityTier::Delivery, $scratch->file('assembled.mp4'));

        expect($result['output']->frameCount)->toBe(168)
            ->and($result['output']->keyframeIntervalFrames)->toBe(24)
            ->and(array_column($result['measurements'], 'operation'))->toBe([
                'video.trim',
                'video.transition_segment',
                'video.trim',
                'video.transition_segment',
                'video.trim',
                'video.concat',
            ])
            ->and(count($result['operations']))->toBe(6);

        foreach ($result['measurements'] as $measurement) {
            expect($measurement['media_duration_ms'])->toBeGreaterThan(0)
                ->and($measurement['processing_duration_ms'])->toBeGreaterThanOrEqual(0)
                ->and($measurement['output_size_bytes'])->toBeGreaterThan(0);
        }

        $verifier = new BenchmarkOutputVerifier($driver, new FfmpegDiagnostics);

        foreach ($result['operations'] as $index => $operation) {
            $violations = $verifier->streamCopyViolations($compiler->compile($operation));

            if ($operation instanceof VideoTransitionSegment) {
                expect($violations)->not->toBe([]);
            } else {
                expect($violations)->toBe([], "Stream-copy violation at operation {$index}: ".implode('; ', $violations));
            }
        }
    });
});

test('rejects assemblies the vocabulary would have to reject later', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $assembly = new HybridDeliveryAssembly(RecipeCompiler::fromSystem(), new FakeDriver);
        $output = $scratch->file('assembled.mp4');
        $singleInput = fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'single', 3.0));
        $inputs = [
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'first', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'second', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'third', 3.0)),
        ];

        expect(fn (): array => $assembly->plan([$singleInput], 24, QualityTier::Delivery, $output))
            ->toThrow(InvalidArgumentException::class, 'at least two')
            ->and(fn (): array => $assembly->plan($inputs, 18, QualityTier::Delivery, $output))
            ->toThrow(InvalidArgumentException::class, 'guaranteed keyframe grid')
            ->and(fn (): array => $assembly->plan($inputs, 24, QualityTier::Intermediate, $output))
            ->toThrow(InvalidArgumentException::class, 'match every input tier')
            ->and(fn (): array => $assembly->plan(
                [
                    fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'short', 1.0)),
                    fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'second', 3.0)),
                ],
                24,
                QualityTier::Delivery,
                $output,
            ))->toThrow(InvalidArgumentException::class, 'too short')
            ->and(fn (): array => $assembly->plan(
                [
                    fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'first', 3.0)),
                    fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'short-middle', 2.0)),
                    fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'third', 3.0)),
                ],
                24,
                QualityTier::Delivery,
                $output,
            ))->toThrow(InvalidArgumentException::class, 'too short');
    });
});

test('rejects inputs with mismatched dimensions', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $assembly = new HybridDeliveryAssembly(RecipeCompiler::fromSystem(), new FakeDriver);
        $output = $scratch->file('assembled.mp4');
        $portrait = new VideoStillClip(
            $scratch->file('portrait.png'),
            $scratch->file('portrait.mp3'),
            $scratch->file('portrait.mp4'),
            1024,
            1536,
            24,
            3.0,
            VideoFit::ContainPad,
            VideoColor::Black,
            new StillMotion,
            1,
            QualityTier::Delivery,
            AudioBitrate::Kbps192,
        );

        expect(fn (): array => $assembly->plan(
            [
                fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'first', 3.0)),
                fakeRenderedSlateVideo($portrait),
            ],
            24,
            QualityTier::Delivery,
            $output,
        ))->toThrow(InvalidArgumentException::class, 'matching dimensions');
    });
});

test('keeps the xfade vocabulary out of the assembly splice', function (): void {
    resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
        $inputs = [
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'first', 3.0)),
            fakeRenderedSlateVideo(hybridAssemblySourceClip($scratch, 'second', 3.0)),
        ];
        $assembly = new HybridDeliveryAssembly(RecipeCompiler::fromSystem(), hybridAssemblyFakeDriver(hybridAssemblyStepProperties(72, 72)));
        $result = $assembly->assemble($inputs, 24, QualityTier::Delivery, $scratch->file('assembled.mp4'));

        $concat = $result['operations'][count($result['operations']) - 1];

        expect($concat)->toBeInstanceOf(VideoConcat::class)
            ->and($concat->mode)->toBe(VideoConcatMode::Copy)
            ->and($concat->transition)->toBeNull()
            ->and($concat->audioCrossfade)->toBeFalse();
    });
});
