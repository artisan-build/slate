<?php

declare(strict_types=1);

use App\Slate\Ingest\IngestManager;
use App\Slate\Mcp\McpWriteRefused;
use App\Slate\Mcp\McpWriteService;
use App\Slate\Run\RunManager;
use App\Slate\Run\RunRecipeAdmission;
use App\Slate\Run\RunSubmissionException;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\ObjectKey;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Sleep;
use Psr\Http\Message\StreamInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

[$script, $operation, $barrierDirectory, $participant, $payload] = $argv;
$arguments = json_decode(base64_decode($payload, true), true, flags: JSON_THROW_ON_ERROR);

file_put_contents($barrierDirectory.'/'.$participant.'.ready', 'ready');

$deadline = microtime(true) + 10;

while (! is_file($barrierDirectory.'/release')) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, 'Timed out waiting for the concurrency barrier.');
        exit(2);
    }

    Sleep::usleep(10_000);
}

$owner = new Subject(SubjectType::Installation, 'postgres-mcp-owner');
$writes = $application->make(McpWriteService::class);

try {
    if ($operation === 'source') {
        $storage = new class implements IngestStorage
        {
            public function exists(ObjectKey $objectKey): bool
            {
                return false;
            }

            public function size(ObjectKey $objectKey): int
            {
                return 0;
            }

            public function beginMultipartUpload(ObjectKey $objectKey): string
            {
                return '';
            }

            public function abortMultipartUpload(ObjectKey $objectKey, string $uploadId): void {}

            public function uploadedParts(ObjectKey $objectKey, string $uploadId): array
            {
                return [];
            }

            public function uploadPart(
                ObjectKey $objectKey,
                string $uploadId,
                int $partNumber,
                StreamInterface $stream,
                int $contentLength,
            ): string {
                return '';
            }

            public function completeMultipartUpload(
                ObjectKey $objectKey,
                string $uploadId,
                array $etagsByPartNumber,
            ): void {}

            public function delete(ObjectKey $objectKey): void {}

            public function temporaryUploadUrl(
                ObjectKey $objectKey,
                DateTimeInterface $expiration,
                bool $singleUse = false,
            ): array {
                return ['url' => '', 'headers' => []];
            }
        };
        $ingestManager = new IngestManager($storage);
        $result = $writes->execute(
            $owner,
            'create_remote_source',
            $arguments['idempotency_key'],
            ['url' => $arguments['url']],
            'source',
            $arguments['source_id'],
            function () use ($arguments, $ingestManager, $owner): array {
                $source = $ingestManager->queueRemoteSource($owner, $arguments['url'], $arguments['source_id']);
                Sleep::usleep(250_000);

                return ['id' => $source->id];
            },
        );
    } elseif ($operation === 'run') {
        $result = $writes->execute(
            $owner,
            'create_run',
            $arguments['idempotency_key'],
            ['run_id' => $arguments['run_id'], 'recipe' => $arguments['recipe']],
            'run',
            $arguments['run_id'],
            function () use ($application, $arguments, $owner): array {
                try {
                    $recipe = $application->make(RunRecipeAdmission::class)->admit($arguments['recipe'], $owner);
                    $run = $application->make(RunManager::class)->create($arguments['run_id'], $recipe, $owner);
                    Sleep::usleep(250_000);
                } catch (RunSubmissionException $exception) {
                    throw new McpWriteRefused($exception->reason->value);
                }

                return ['id' => $run->id];
            },
        );
    } else {
        throw new RuntimeException('Unknown concurrency operation.');
    }

    fwrite(STDOUT, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
