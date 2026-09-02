<?php

use App\Slate\Driver\CompiledRecipe;

test('accepts compiler-owned local binary arguments', function (): void {
    $recipe = new CompiledRecipe(['/runtime/ffmpeg/bin/ffmpeg', '-i', '/tmp/input.mp4']);

    expect($recipe->command())->toBe(['/runtime/ffmpeg/bin/ffmpeg', '-i', '/tmp/input.mp4']);
});

test('rejects commands outside the pinned binary boundary', function (array $command): void {
    expect(fn (): CompiledRecipe => new CompiledRecipe($command))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'different executable' => [['/bin/sh', '-c', 'true']],
    'HTTP input' => [['/runtime/ffmpeg/bin/ffmpeg', '-i', 'https://example.com/input.mp4']],
    'concat protocol' => [['/runtime/ffmpeg/bin/ffmpeg', '-i', 'concat:/tmp/one.mp4|/tmp/two.mp4']],
    'file protocol' => [['/runtime/ffmpeg/bin/ffmpeg', '-i', 'file:/etc/passwd']],
    'pipe protocol' => [['/runtime/ffmpeg/bin/ffmpeg', '-i', 'pipe:0']],
    'data protocol' => [['/runtime/ffmpeg/bin/ffmpeg', '-i', 'data:text/plain,secret']],
    'subfile protocol' => [['/runtime/ffmpeg/bin/ffmpeg', '-i', 'subfile,,start,0,end,10,,:/etc/passwd']],
]);
