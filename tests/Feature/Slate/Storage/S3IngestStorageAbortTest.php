<?php

declare(strict_types=1);

namespace Tests\Feature\Slate\Storage;

use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\S3IngestStorage;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class S3IngestStorageAbortTest extends TestCase
{
    public function test_abort_tolerates_no_such_upload_when_the_upload_is_already_gone(): void
    {
        $storage = $this->storage(function (MockInterface $client): void {
            $client->shouldReceive('abortMultipartUpload')->once()->andThrow(
                new S3Exception('NoSuchUpload', new Command('AbortMultipartUpload'), [
                    'code' => 'NoSuchUpload',
                ]),
            );
        });

        $storage->abortMultipartUpload($this->objectKey(), 'upload-completed-elsewhere');

        self::assertTrue(true);
    }

    public function test_abort_wraps_other_s3_error_codes(): void
    {
        $storage = $this->storage(function (MockInterface $client): void {
            $client->shouldReceive('abortMultipartUpload')->once()->andThrow(
                new S3Exception('AccessDenied', new Command('AbortMultipartUpload'), [
                    'code' => 'AccessDenied',
                ]),
            );
        });

        try {
            $storage->abortMultipartUpload($this->objectKey(), 'upload-unavailable');
            self::fail('Expected the abort failure to be wrapped.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to abort multipart ingest: slate-sources/test/source', $exception->getMessage());
            self::assertInstanceOf(S3Exception::class, $exception->getPrevious());
            self::assertSame('AccessDenied', $exception->getPrevious()?->getAwsErrorCode());
        }
    }

    public function test_abort_wraps_non_s3_failures(): void
    {
        $storage = $this->storage(function (MockInterface $client): void {
            $client->shouldReceive('abortMultipartUpload')->once()->andThrow(
                new RuntimeException('connection reset'),
            );
        });

        try {
            $storage->abortMultipartUpload($this->objectKey(), 'upload-unreachable');
            self::fail('Expected the abort failure to be wrapped.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to abort multipart ingest: slate-sources/test/source', $exception->getMessage());
            self::assertSame('connection reset', $exception->getPrevious()?->getMessage());
        }
    }

    /**
     * @param  callable(MockInterface): void  $configure
     */
    private function storage(callable $configure): S3IngestStorage
    {
        $client = Mockery::mock(S3ClientInterface::class);
        $configure($client);

        /** @var AwsS3V3Adapter&MockInterface $filesystem */
        $filesystem = Mockery::mock(AwsS3V3Adapter::class);
        $filesystem->shouldReceive('getClient')->andReturn($client);
        $filesystem->shouldReceive('getConfig')->andReturn(['bucket' => 'test-bucket', 'root' => '']);

        return new S3IngestStorage($filesystem);
    }

    private function objectKey(): ObjectKey
    {
        return ObjectKey::withinPrefix('slate-sources/test', 'slate-sources/test/source');
    }
}
