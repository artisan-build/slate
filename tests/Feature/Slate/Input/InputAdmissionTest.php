<?php

namespace Tests\Feature\Slate\Input;

use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\Operation\ProbeCompiler;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\DriverResult;
use App\Slate\Driver\FakeDriver;
use App\Slate\Input\InputAdmission;
use App\Slate\Input\InputRejectedException;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ScratchDirectory;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InputAdmissionTest extends TestCase
{
    public function test_rejects_playlist_bytes_before_compiling_or_running_a_probe(): void
    {
        resolve(JobScratch::class)->run(function (ScratchDirectory $scratch): void {
            $input = $scratch->file('playlist.m3u8');
            file_put_contents($input->writablePath(), "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nhttp://attacker.test/video.ts\n");
            $driver = new FakeDriver;
            $compiler = new RecipeCompiler(new ProbeCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')));

            try {
                (new InputAdmission)->inspectVideo($input, $compiler, $driver);
                self::fail('Expected playlist input to be rejected.');
            } catch (InputRejectedException $exception) {
                self::assertSame(InputRejectionReason::ReferencedMedia, $exception->reason);
            }

            self::assertSame([], $driver->recipes());
        });
    }

    #[DataProvider('selfContainedContainerSignatures')]
    public function test_admits_each_self_contained_container_signature(string $bytes): void
    {
        $stream = Utils::streamFor($bytes);

        (new InputAdmission)->inspectStream($stream, true);

        self::assertSame(0, $stream->tell());
    }

    public function test_rejects_a_structured_quicktime_reference_movie(): void
    {
        $fileType = pack('N', 16).'ftyp'.'qt  '.'qt  ';
        $reference = pack('N', 8).'rmra';
        $movie = pack('N', 8 + strlen($reference)).'moov'.$reference;

        try {
            (new InputAdmission)->inspectStream(Utils::streamFor($fileType.$movie), true);
            self::fail('Expected QuickTime reference movie rejection.');
        } catch (InputRejectedException $exception) {
            self::assertSame(InputRejectionReason::ReferencedMedia, $exception->reason);
        }
    }

    #[DataProvider('structuralCapFailures')]
    public function test_rejects_each_structural_cap_with_a_typed_reason(
        array $probeProperties,
        InputRejectionReason $expectedReason,
    ): void {
        resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($probeProperties, $expectedReason): void {
            $input = $scratch->file('source.mp4');
            file_put_contents($input->writablePath(), "\0\0\0\x18ftypisom\0\0\0\0isom");
            $driver = new FakeDriver(new DriverResult(json_encode($probeProperties, JSON_THROW_ON_ERROR)));
            $compiler = new RecipeCompiler(new ProbeCompiler(new FfmpegLocator('Linux', 'arm64', '/runtime')));

            try {
                (new InputAdmission)->inspectVideo($input, $compiler, $driver);
                self::fail('Expected structural input rejection.');
            } catch (InputRejectedException $exception) {
                self::assertSame($expectedReason, $exception->reason);
            }

            self::assertCount(1, $driver->recipes());
        });
    }

    /** @return iterable<string, array{array<string, mixed>, InputRejectionReason}> */
    public static function structuralCapFailures(): iterable
    {
        $validVideo = [
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => 1920,
            'height' => 1080,
            'avg_frame_rate' => '30/1',
        ];

        yield 'duration' => [
            ['format' => ['duration' => '14401'], 'streams' => [$validVideo]],
            InputRejectionReason::DurationExceeded,
        ];
        yield 'resolution' => [
            ['format' => ['duration' => '10'], 'streams' => [[...$validVideo, 'width' => 4097]]],
            InputRejectionReason::ResolutionExceeded,
        ];
        yield 'secondary video resolution' => [
            [
                'format' => ['duration' => '10'],
                'streams' => [$validVideo, [...$validVideo, 'height' => 4097]],
            ],
            InputRejectionReason::ResolutionExceeded,
        ];
        yield 'stream count' => [
            ['format' => ['duration' => '10'], 'streams' => array_fill(0, 9, $validVideo)],
            InputRejectionReason::StreamCountExceeded,
        ];
        yield 'decode codec' => [
            ['format' => ['duration' => '10'], 'streams' => [[...$validVideo, 'codec_name' => 'unknown']]],
            InputRejectionReason::DecodeCodecNotAllowed,
        ];
        yield 'frame rate' => [
            ['format' => ['duration' => '10'], 'streams' => [[...$validVideo, 'avg_frame_rate' => '61/1']]],
            InputRejectionReason::FrameRateExceeded,
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function selfContainedContainerSignatures(): iterable
    {
        yield 'MP4 MOV M4A family' => ["\0\0\0\x18ftypisom\0\0\0\0isom"];
        yield 'WebM Matroska family' => ["\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01"];
        yield 'MP3 ID3' => ['ID3'.str_repeat("\0", 16)];
        yield 'WAV' => ['RIFF'.pack('V', 4).'WAVE'];
        yield 'AAC ADTS' => ["\xFF\xF1\x50\x80"];
    }
}
