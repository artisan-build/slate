<?php

use App\Slate\Run\RunRecipeObjectKeys;
use App\Slate\Storage\ObjectKey;
use App\Slate\Storage\SourceObjectKeys;

test('admits a stored recipe key inside the run prefix', function (): void {
    $runPrefix = 'slate-runs/41414141-4141-4141-8141-414141414141';

    $objectKey = RunRecipeObjectKeys::fromStored($runPrefix, $runPrefix.'/work/operations/0000/output.mp4');

    expect($objectKey->value())->toBe($runPrefix.'/work/operations/0000/output.mp4');
});

test('admits a server-derived source key from a stored recipe', function (): void {
    $sourceId = 'ec2ae800-0000-4000-8000-000000000000';

    $objectKey = RunRecipeObjectKeys::fromStored(
        'slate-runs/41414141-4141-4141-8141-414141414141',
        "slate-sources/{$sourceId}/source",
    );

    expect($objectKey->value())->toBe("slate-sources/{$sourceId}/source");
});

test('refuses a source-namespace key that is not the server-derived source path', function (string $key): void {
    expect(fn (): ObjectKey => RunRecipeObjectKeys::fromStored(
        'slate-runs/41414141-4141-4141-8141-414141414141',
        $key,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'foreign suffix under own source id' => ['slate-sources/ec2ae800-0000-4000-8000-000000000000/output.mp4'],
    'shared namespace without an id' => ['slate-sources/stolen/source'],
]);

test('refuses a stored recipe key outside both the run prefix and the source namespace', function (): void {
    expect(fn (): ObjectKey => RunRecipeObjectKeys::fromStored(
        'slate-runs/41414141-4141-4141-8141-414141414141',
        'slate-runs/99999999-9999-4999-8999-999999999999/output.mp4',
    ))->toThrow(InvalidArgumentException::class, 'Object key must remain within the caller prefix.');
});

test('derives source object keys only from a UUID source ID', function (string $sourceId): void {
    expect(fn (): string => SourceObjectKeys::prefix($sourceId))
        ->toThrow(InvalidArgumentException::class, 'UUID source ID');
})->with([
    'plain string' => 'test',
    'empty' => '',
    'traversal' => '../customer-456',
]);

test('re-parses only the server-derived source path', function (): void {
    $sourceId = 'ec2ae800-0000-4000-8000-000000000000';

    $objectKey = SourceObjectKeys::fromValue("slate-sources/{$sourceId}/source");

    expect($objectKey->value())->toBe("slate-sources/{$sourceId}/source")
        ->and(fn (): ObjectKey => SourceObjectKeys::fromValue("slate-sources/{$sourceId}/output.mp4"))
        ->toThrow(InvalidArgumentException::class, 'server-derived source path')
        ->and(fn (): ObjectKey => SourceObjectKeys::fromValue('slate-sources/not-a-uuid/source'))
        ->toThrow(InvalidArgumentException::class);
});
