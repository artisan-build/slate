<?php

declare(strict_types=1);

namespace Tests\Support;

final class MinioConfiguration
{
    public const string AccessKey = 'slate-minio';

    public const string SecretKey = 'slate-minio-secret';

    public const string Region = 'us-east-1';

    public const string Bucket = 'slate-tests';

    public const string Host = '127.0.0.1';

    public const int Port = 19000;

    public const string Endpoint = 'http://127.0.0.1:19000';

    /**
     * @param  array<string, mixed>  $configuredDisk
     * @return array<string, mixed>
     */
    public static function disk(array $configuredDisk): array
    {
        return [...$configuredDisk, ...[
            'key' => self::AccessKey,
            'secret' => self::SecretKey,
            'region' => self::Region,
            'bucket' => self::Bucket,
            'endpoint' => self::Endpoint,
            'use_path_style_endpoint' => true,
        ]];
    }
}
