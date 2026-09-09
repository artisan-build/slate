<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Api\V1;

use App\Models\Source;
use App\Models\SourceStatus;
use App\Slate\Driver\Binary\FfmpegLocator;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioChannelLayout;
use App\Slate\Recipe\Audio\AudioFormat;
use App\Slate\Recipe\Audio\AudioSampleRate;
use App\Slate\Recipe\Audio\AudioSilence;
use App\Slate\Recipe\Probe;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\StillMotion;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\RunObjectKeys;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\MinioConfiguration;
use Tests\TestCase;

#[Group('ffmpeg-render')]
final class RunDeliveryRenderTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithCredentials;

    public function test_three_captioned_pages_run_over_http_to_one_probed_delivery_on_database_queue_and_minio(): void
    {
        $this->requireRuntime();
        $this->configureMinio();
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        try {
            resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($credential): void {
                $compiler = resolve(RecipeCompiler::class);
                $driver = resolve(Driver::class);
                $audio = $scratch->file('narration.mp3');
                $audioResult = $driver->run($compiler->compile(new AudioSilence(
                    $audio,
                    3.0,
                    AudioSampleRate::Hz44100,
                    AudioChannelLayout::Mono,
                    AudioFormat::Mp3,
                    AudioBitrate::Kbps192,
                )));
                self::assertTrue($audioResult->successful(), $audioResult->errorOutput);
                $audioSource = $this->uploadReadySource($credential, $audio, 'audio/mpeg');
                $font = $scratch->file('captions.ttf');
                self::assertTrue(copy(resource_path('fonts/NotoSans[wdth,wght].ttf'), $font->writablePath()));
                $fontSource = $this->uploadReadySource($credential, $font, 'font/ttf');
                $pages = [];

                foreach ([[255, 0, 0], [0, 255, 0], [0, 0, 255]] as $index => [$red, $green, $blue]) {
                    $image = $scratch->file("page-{$index}.png");
                    file_put_contents($image->writablePath(), self::png(3, 2, $red, $green, $blue));
                    $imageSource = $this->uploadReadySource($credential, $image, 'image/png');
                    $pages[] = [
                        'image' => $imageSource->id,
                        'audio' => $audioSource->id,
                        'duration' => 3.0,
                        'captions' => [
                            'font' => $fontSource->id,
                            'words' => [[
                                'text' => 'Page '.($index + 1),
                                'x' => 120,
                                'y' => 760,
                                'start' => 0.25,
                                'end' => 2.5,
                            ]],
                        ],
                    ];
                }

                $runId = '71717171-7171-4171-8171-717171717171';
                $this->actingAsCredential($credential)->postJson('/api/v1/runs', [
                    'id' => $runId,
                    'recipe' => ['delivery' => [
                        'pages' => $pages,
                        'transitions' => ['type' => 'fade', 'duration' => 1.0],
                        'quality_tier' => 'delivery',
                        'width' => 1536,
                        'height' => 1024,
                    ]],
                ])->assertAccepted()->assertExactJson(['id' => $runId]);

                $this->workDatabaseQueue();
                $this->getJson('/api/v1/runs/'.$runId)->assertOk()->assertJsonPath('status', 'succeeded');
                $properties = $this->probeOutput($runId, $scratch->file('book-delivery.mp4'));
                $this->assertDeliveryProperties($properties, 7.0);
                fwrite(STDOUT, "\nE2b-1 ffprobe:\n".json_encode($properties, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            });
        } finally {
            $this->cleanupMinio();
        }
    }

    public function test_three_highlights_from_a_real_probe_ready_source_run_to_one_probed_delivery(): void
    {
        $this->requireRuntime();
        $this->configureMinio();
        $credential = $this->mintCredential(['abilities' => ['consume']]);

        try {
            resolve(JobScratch::class)->run(function (ScratchDirectory $scratch) use ($credential): void {
                $compiler = resolve(RecipeCompiler::class);
                $driver = resolve(Driver::class);
                $image = $scratch->file('highlight-source.ppm');
                file_put_contents($image->writablePath(), "P3\n3 2\n255\n".implode('  ', array_fill(0, 6, '80 120 200'))."\n");
                $audio = $scratch->file('highlight-source.mp3');
                $audioResult = $driver->run($compiler->compile(new AudioSilence(
                    $audio,
                    12.0,
                    AudioSampleRate::Hz44100,
                    AudioChannelLayout::Mono,
                    AudioFormat::Mp3,
                    AudioBitrate::Kbps192,
                )));
                self::assertTrue($audioResult->successful(), $audioResult->errorOutput);
                $video = SlateVideo::render(new VideoStillClip(
                    $image,
                    $audio,
                    $scratch->file('highlight-source.mp4'),
                    1536,
                    1024,
                    24,
                    12.0,
                    VideoFit::ContainPad,
                    VideoColor::Black,
                    new StillMotion,
                    1,
                    QualityTier::Delivery,
                    AudioBitrate::Kbps192,
                ), $compiler, $driver);
                $bytes = filesize($video->path->readablePath());
                self::assertIsInt($bytes);
                $upload = $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
                    'upload' => ['content_type' => 'video/mp4', 'size' => $bytes],
                ])->assertCreated();
                $stream = fopen($video->path->readablePath(), 'rb');
                self::assertIsResource($stream);

                try {
                    $put = Http::withHeaders($upload->json('headers'))
                        ->withBody($stream, 'video/mp4')
                        ->put($upload->json('put_url'));
                } finally {
                    fclose($stream);
                }

                self::assertTrue($put->successful(), $put->body());
                $sourceId = $upload->json('id');
                self::assertIsString($sourceId);
                $this->getJson('/api/v1/sources/'.$sourceId)->assertOk()->assertJsonPath('status', 'ingesting');
                $this->workDatabaseQueue();
                $this->getJson('/api/v1/sources/'.$sourceId)->assertOk()->assertJsonPath('status', 'ready');
                self::assertSame(SourceStatus::Ready, Source::query()->findOrFail($sourceId)->status);

                $runId = '72727272-7272-4272-8272-727272727272';
                $this->postJson('/api/v1/runs', [
                    'id' => $runId,
                    'recipe' => ['highlights' => [
                        'source' => $sourceId,
                        'clips' => [
                            ['start' => 0.0, 'end' => 3.0],
                            ['start' => 4.0, 'end' => 7.0],
                            ['start' => 8.0, 'end' => 11.0],
                        ],
                        'transitions' => ['type' => 'fade', 'duration' => 1.0],
                        'quality_tier' => 'delivery',
                    ]],
                ])->assertAccepted()->assertExactJson(['id' => $runId]);

                $this->workDatabaseQueue();
                $this->getJson('/api/v1/runs/'.$runId)->assertOk()->assertJsonPath('status', 'succeeded');
                $properties = $this->probeOutput($runId, $scratch->file('highlights-delivery.mp4'));
                $this->assertDeliveryProperties($properties, 7.0);
                fwrite(STDOUT, "\nE2b-2 ffprobe:\n".json_encode($properties, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            });
        } finally {
            $this->cleanupMinio();
        }
    }

    private function requireRuntime(): void
    {
        if (FfmpegLocator::fromSystem()->isProvisioned()) {
            return;
        }

        $message = 'Pinned ffmpeg runtime unavailable; delivery API render lane not run.';

        if (filter_var(getenv('SLATE_REQUIRE_FFMPEG'), FILTER_VALIDATE_BOOL)) {
            self::fail($message.' SLATE_REQUIRE_FFMPEG requires this lane to run.');
        }

        self::markTestSkipped($message);
    }

    private function configureMinio(): void
    {
        $socket = @fsockopen(MinioConfiguration::Host, MinioConfiguration::Port, $errorCode, $errorMessage, 1);

        if (! is_resource($socket)) {
            $message = 'MinIO unavailable at '.MinioConfiguration::Endpoint." ({$errorCode}: {$errorMessage}).";

            if (filter_var(getenv('SLATE_REQUIRE_MINIO'), FILTER_VALIDATE_BOOL)) {
                self::fail($message.' SLATE_REQUIRE_MINIO requires this lane to run.');
            }

            self::markTestSkipped($message);
        }

        fclose($socket);
        $configuredDisk = config('filesystems.disks.s3');
        self::assertIsArray($configuredDisk);
        $disk = MinioConfiguration::disk($configuredDisk);
        $client = new S3Client([
            'version' => 'latest',
            'region' => $disk['region'],
            'endpoint' => $disk['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $disk['key'], 'secret' => $disk['secret']],
        ]);

        if (! $client->doesBucketExist(MinioConfiguration::Bucket)) {
            $client->createBucket(['Bucket' => MinioConfiguration::Bucket]);
        }

        config()->set('filesystems.default', 's3');
        config()->set('filesystems.disks.s3', $disk);
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 180);
        Storage::forgetDisk('s3');
    }

    private function uploadReadySource(
        MintedTestCredential $credential,
        ScratchPath $path,
        string $contentType,
    ): Source {
        $bytes = filesize($path->readablePath());

        if (! is_int($bytes)) {
            throw new RuntimeException('Unable to measure delivery test source.');
        }

        $upload = $this->actingAsCredential($credential)->postJson('/api/v1/sources', [
            'upload' => ['content_type' => $contentType, 'size' => $bytes],
        ])->assertCreated();
        $stream = fopen($path->readablePath(), 'rb');
        self::assertIsResource($stream);

        try {
            $put = Http::withHeaders($upload->json('headers'))
                ->withBody($stream, $contentType)
                ->put($upload->json('put_url'));
        } finally {
            fclose($stream);
        }

        self::assertTrue($put->successful(), $put->body());
        $sourceId = $upload->json('id');
        self::assertIsString($sourceId);
        $this->getJson('/api/v1/sources/'.$sourceId)->assertOk()->assertJsonPath('status', 'ingesting');
        $this->workDatabaseQueue();
        $this->getJson('/api/v1/sources/'.$sourceId)->assertOk()->assertJsonPath('status', 'ready');
        $source = Source::query()->findOrFail($sourceId);
        self::assertSame(SourceStatus::Ready, $source->status);

        return $source;
    }

    private static function png(int $width, int $height, int $red, int $green, int $blue): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            .$type.$data.pack('N', crc32($type.$data));
        $pixels = str_repeat("\0".str_repeat(pack('CCC', $red, $green, $blue), $width), $height);

        return "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($pixels))
            .$chunk('IEND', '');
    }

    private function workDatabaseQueue(): void
    {
        $worker = resolve('queue.worker');
        self::assertInstanceOf(Worker::class, $worker);
        $options = new WorkerOptions(sleep: 0, timeout: 170, maxTries: 1, force: true);

        for ($attempt = 0; $attempt < 100 && DB::table('jobs')->count() > 0; $attempt++) {
            $worker->runNextJob('database', 'ingest,default', $options);
        }

        self::assertSame(0, DB::table('jobs')->count(), 'The database queue did not drain.');
        self::assertSame(0, DB::table('failed_jobs')->count(), 'A delivery job failed.');
    }

    /** @return array<string, mixed> */
    private function probeOutput(string $runId, ScratchPath $localPath): array
    {
        resolve(ObjectStorage::class)->stageIn(RunObjectKeys::deliveryOutput($runId), $localPath);
        $probe = resolve(Driver::class)->run(resolve(RecipeCompiler::class)->compile(new Probe($localPath)));
        self::assertTrue($probe->successful(), $probe->errorOutput);
        $properties = json_decode($probe->output, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($properties);

        return $properties;
    }

    /** @param array<string, mixed> $properties */
    private function assertDeliveryProperties(array $properties, float $duration): void
    {
        $streams = $properties['streams'] ?? [];
        self::assertIsArray($streams);
        $video = collect($streams)->firstWhere('codec_type', 'video');
        $audio = collect($streams)->firstWhere('codec_type', 'audio');
        self::assertIsArray($video);
        self::assertIsArray($audio);
        self::assertSame('h264', $video['codec_name'] ?? null);
        self::assertSame('yuv420p', $video['pix_fmt'] ?? null);
        self::assertSame('aac', $audio['codec_name'] ?? null);
        self::assertEqualsWithDelta($duration, (float) data_get($properties, 'format.duration'), 0.05);
    }

    private function cleanupMinio(): void
    {
        if (config('filesystems.default') === 's3') {
            Storage::disk()->deleteDirectory('slate-runs');
            Storage::disk()->deleteDirectory('slate-sources');
            Storage::forgetDisk('s3');
        }
    }
}
