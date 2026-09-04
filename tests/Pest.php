<?php

use App\Slate\Driver\Binary\CompiledArgument;
use App\Slate\Driver\Binary\CompiledFilterGraph;
use App\Slate\Driver\Binary\CompiledInput;
use App\Slate\Driver\Binary\CompiledOutput;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\CompiledRecipe;
use App\Slate\Driver\Driver;
use App\Slate\Driver\DriverResult;
use App\Slate\Recipe\Caption\TimedWords;
use App\Slate\Recipe\Operation;
use App\Slate\Recipe\Video\OverlayImage;
use App\Slate\Recipe\Video\SlateVideo;
use App\Slate\Recipe\Video\VideoAudioMux;
use App\Slate\Recipe\Video\VideoConcat;
use App\Slate\Recipe\Video\VideoStillClip;
use App\Slate\Recipe\Video\VideoStillClipSlice;
use App\Slate\Recipe\Video\VideoTransitionSegment;
use App\Slate\Recipe\Video\VideoTrim;
use App\Slate\Storage\ScratchDirectory;
use App\Slate\Storage\ScratchPath;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

function ffmpegFixture(string $name): string
{
    $contents = file_get_contents(__DIR__.'/Fixtures/'.$name);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to load fixture {$name}.");
    }

    return $contents;
}

/** @param  array<string, mixed>  $videoStreamOverrides */
function fakeRenderedSlateVideo(
    VideoStillClip|VideoStillClipSlice|VideoAudioMux|VideoTrim|VideoTransitionSegment|VideoConcat|OverlayImage|TimedWords $operation,
    ?float $durationSeconds = null,
    array $videoStreamOverrides = [],
    bool $includeVideo = true,
    bool $includeAudio = true,
    string $audioCodec = 'aac',
): SlateVideo {
    if ($operation instanceof VideoStillClip) {
        file_put_contents($operation->image->writablePath(), 'image');
        file_put_contents($operation->audio->writablePath(), 'audio');
        $width = $operation->width;
        $height = $operation->height;
        $framesPerSecond = $operation->framesPerSecond;
        $durationSeconds ??= $operation->durationSeconds;
    } elseif ($operation instanceof VideoStillClipSlice) {
        file_put_contents($operation->image->writablePath(), 'image');
        $width = $operation->width;
        $height = $operation->height;
        $framesPerSecond = $operation->framesPerSecond;
        $durationSeconds ??= $operation->durationSeconds();
        $includeAudio = false;
    } elseif ($operation instanceof VideoAudioMux) {
        file_put_contents($operation->audio->writablePath(), 'audio');
        $width = $operation->video->width;
        $height = $operation->video->height;
        $framesPerSecond = $operation->video->framesPerSecond;
        $durationSeconds ??= $operation->video->durationSeconds;
        $includeAudio = true;
    } elseif ($operation instanceof VideoConcat) {
        $width = $operation->width();
        $height = $operation->height();
        $framesPerSecond = $operation->framesPerSecond;
        $durationSeconds ??= $operation->outputDurationSeconds();
    } elseif ($operation instanceof VideoTrim) {
        $width = $operation->input->width;
        $height = $operation->input->height;
        $framesPerSecond = $operation->input->framesPerSecond;
        $durationSeconds ??= $operation->durationSeconds();
    } elseif ($operation instanceof VideoTransitionSegment) {
        $width = $operation->from->width;
        $height = $operation->from->height;
        $framesPerSecond = $operation->framesPerSecond();
        $durationSeconds ??= $operation->durationSeconds();
    } elseif ($operation instanceof OverlayImage) {
        file_put_contents($operation->image->writablePath(), 'image');
        $width = $operation->input->width;
        $height = $operation->input->height;
        $framesPerSecond = $operation->input->framesPerSecond;
        $durationSeconds ??= $operation->input->durationSeconds;
    } else {
        $width = $operation->input->width;
        $height = $operation->input->height;
        $framesPerSecond = $operation->input->framesPerSecond;
        $durationSeconds ??= $operation->input->durationSeconds;
    }

    $streams = [];

    if ($includeVideo) {
        $streams[] = array_replace([
            'codec_type' => 'video',
            'codec_name' => 'h264',
            'width' => $width,
            'height' => $height,
            'avg_frame_rate' => $framesPerSecond.'/1',
            'pix_fmt' => 'yuv420p',
            'color_range' => 'tv',
            'nb_frames' => (string) round($durationSeconds * $framesPerSecond),
        ], $videoStreamOverrides);
    }

    if ($includeAudio) {
        $streams[] = [
            'codec_type' => 'audio',
            'codec_name' => $audioCodec,
        ];
    }

    $properties = [
        'streams' => $streams,
        'format' => ['duration' => number_format($durationSeconds, 6, '.', '')],
    ];
    $driver = new class($operation->output, $properties) implements Driver
    {
        private int $calls = 0;

        /** @param  array<string, mixed>  $properties */
        public function __construct(
            private readonly ScratchPath $output,
            private readonly array $properties,
        ) {}

        public function run(CompiledRecipe $recipe): DriverResult
        {
            if ($this->calls++ === 0) {
                if (is_file($this->output->writablePath())) {
                    throw new RuntimeException('Slate render did not clear the stale output before execution.');
                }

                file_put_contents($this->output->writablePath(), 'rendered by fake driver');

                return new DriverResult;
            }

            return new DriverResult(json_encode($this->properties, JSON_THROW_ON_ERROR));
        }
    };

    return SlateVideo::render(
        $operation,
        RecipeCompiler::fromSystem(),
        $driver,
    );
}

/**
 * Finds documented encoder literals in PHP files beneath a directory.
 *
 * This is a source-text scan: it cannot detect names assembled from fragments at runtime. It enforces
 * only the documented encoder names in its regex; capability diagnostics remain an approved literal
 * location because they must inspect the encoder table. Separate scans enforce detectable argv and
 * filter-fragment construction.
 *
 * @return array<string, list<string>>
 */
function encoderNameLocations(string $root): array
{
    $encoderPattern = recognizedEncoderPattern();
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        if (preg_match_all($encoderPattern, $contents, $matches) < 1) {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        foreach (array_unique($matches[0]) as $encoder) {
            $locations[$encoder][] = $relativePath;
        }
    }

    ksort($locations);

    foreach ($locations as $encoder => $paths) {
        sort($paths);
        $locations[$encoder] = $paths;
    }

    return $locations;
}

function recognizedEncoderPattern(): string
{
    return '/\\b(?:'.implode('|', array_map(
        static fn (string $encoder): string => preg_quote($encoder, '/'),
        recognizedEncoderNames(),
    )).')\\b/';
}

/** @return list<string> */
function recognizedEncoderNames(): array
{
    return [
        'aac', 'libaom-av1', 'libkvazaar', 'libmp3lame', 'libopenh264', 'libopus', 'libsvtav1',
        'libvorbis', 'libvpx-vp9', 'libx264', 'libx265', 'mjpeg', 'pcm_s16le', 'prores_ks',
        'h264_amf', 'h264_nvenc', 'h264_v4l2m2m', 'h264_vulkan',
    ];
}

/** @return list<string> */
function recognizedFilterNames(): array
{
    return [
        'acrossfade', 'adelay', 'amovie', 'anullsrc', 'apad', 'ass', 'asetpts', 'atrim', 'concat',
        'drawtext', 'fade', 'format', 'metadata', 'movie', 'overlay', 'scale', 'sendcmd', 'setpts',
        'subtitles', 'trim', 'xfade', 'zoompan',
    ];
}

/**
 * All application workloads now compile through the sealed vocabulary. This list remains explicit so
 * a future migration cannot quietly reintroduce a caller-layer filter exception.
 *
 * @return list<string>
 */
function legacyFfmpegBoundaryExceptions(): array
{
    return [];
}

/**
 * Finds binary authority above the driver boundary without depending on process-call shape.
 *
 * PHP has no namespace-private visibility, so this token scan enforces placement: it finds any
 * FfmpegLocator/path-accessor or CompiledRecipe reference and any string literal containing `ffmpeg`
 * or `ffprobe`. It ignores comments, but cannot detect names assembled from split strings at runtime.
 * A binary path assembled from concatenated fragments is undetectable by source scan, full stop.
 * Existing provisioning and diagnostics are held to an exact location map.
 *
 * @return array<string, list<string>>
 */
function binaryAuthorityLocations(string $root): array
{
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        if (str_starts_with($relativePath, 'Slate/Driver/')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$tokenType, $text] = $token;

            if (in_array($tokenType, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if (in_array($text, ['FfmpegLocator', 'ffmpegPath', 'ffprobePath'], true)
                    || str_ends_with($text, '\\FfmpegLocator')) {
                    $locations['locator or path accessor'][] = $relativePath;
                }

                if ($text === 'CompiledRecipe' || str_ends_with($text, '\\CompiledRecipe')) {
                    $locations['compiled recipe reference'][] = $relativePath;
                }

                if (in_array($text, ['CompiledArgument', 'CompiledFilterGraph', 'CompiledH264OutputOptions', 'CompiledInput', 'CompiledOutput'], true)
                    || str_ends_with($text, '\\CompiledArgument')
                    || str_ends_with($text, '\\CompiledFilterGraph')
                    || str_ends_with($text, '\\CompiledH264OutputOptions')
                    || str_ends_with($text, '\\CompiledInput')
                    || str_ends_with($text, '\\CompiledOutput')) {
                    $locations['compiled output reference'][] = $relativePath;
                }
            }

            if (in_array($tokenType, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/ffmpeg|ffprobe/i', $text) === 1) {
                $locations['binary string literal'][] = $relativePath;
            }
        }
    }

    ksort($locations);

    foreach ($locations as $kind => $paths) {
        $paths = array_values(array_unique($paths));
        sort($paths);
        $locations[$kind] = $paths;
    }

    return $locations;
}

/**
 * Finds the enumerated process-execution primitives above the driver boundary.
 *
 * This token scan detects proc_open, exec, shell_exec, passthru, popen, system, pcntl_exec, pcntl_fork,
 * backticks, Symfony Process, Laravel's Process facade, and constant-string process callables passed to
 * call_user_func. A callee written as a quoted string literal is evaluated and normalized before
 * comparison, regardless of its escape spelling; a callee written as a heredoc or nowdoc, or assembled
 * at runtime, remains undetectable. The host function list is call_user_func only. These scans are defence
 * in depth against careless code and copied patterns. The primary containment combines Driver::run's
 * CompiledRecipe type with source enforcement that confines recipe creation to compilers; a rogue
 * primitive is a separate invocation rather than argv slipping through the driver.
 *
 * @return array<string, list<string>>
 */
function processPrimitiveLocations(string $root): array
{
    $locations = [];
    $processFunctions = ['exec', 'passthru', 'pcntl_exec', 'pcntl_fork', 'popen', 'proc_open', 'shell_exec', 'system'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        if (str_starts_with($relativePath, 'Slate/Driver/')) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        $tokens = token_get_all($contents);

        foreach ($tokens as $index => $token) {
            if ($token === '`') {
                $locations['backtick operator'][] = $relativePath;

                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            [$tokenType, $text] = $token;

            if (! in_array($tokenType, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            $namespaceSeparator = strrpos($text, '\\');
            $unqualifiedName = strtolower($namespaceSeparator === false ? $text : substr($text, $namespaceSeparator + 1));

            if ($text === 'Process' || str_ends_with($text, '\\Process')) {
                $locations['process class reference'][] = $relativePath;
            }

            if (in_array($unqualifiedName, $processFunctions, true)) {
                $locations['process function'][] = $relativePath;
            }

            if ($unqualifiedName === 'call_user_func') {
                $literalCallable = constantStringCallableAfter($tokens, $index);

                if ($literalCallable !== null && in_array($literalCallable, $processFunctions, true)) {
                    $locations['literal process callable'][] = $relativePath;
                }
            }
        }
    }

    ksort($locations);

    foreach ($locations as $kind => $paths) {
        $paths = array_values(array_unique($paths));
        sort($paths);
        $locations[$kind] = $paths;
    }

    return $locations;
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 */
function constantStringCallableAfter(array $tokens, int $functionIndex): ?string
{
    $foundOpeningParenthesis = false;

    for ($index = $functionIndex + 1; $index < count($tokens); $index++) {
        $token = $tokens[$index];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (! $foundOpeningParenthesis) {
            if ($token !== '(') {
                return null;
            }

            $foundOpeningParenthesis = true;

            continue;
        }

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $value = eval('return '.$token[1].';');

        return is_string($value) ? strtolower(ltrim($value, '\\')) : null;
    }

    return null;
}

/**
 * Finds known v1 ffmpeg filter fragments above the driver boundary.
 *
 * This source-text scan recognizes assignments for Slate's documented v1 filter vocabulary, including
 * the `movie=` and `concat=` protocol-bearing disclosure vectors. An unknown filter name or a fragment
 * assembled from concatenated strings is undetectable by source scan, full stop. This is defence in depth;
 * the real containment is that Driver::run accepts only a CompiledRecipe and the binary-reference scan
 * confines recipe construction to the compiler.
 *
 * @param  list<string>  $exceptions
 * @return list<string>
 */
function ffmpegFilterFragmentLocations(string $root, array $exceptions = []): array
{
    return sourcePatternLocations(
        $root,
        '/(?:(?<=[\'",])|(?<=\n)\h*)(?:acrossfade|adelay|amovie|anullsrc|apad|ass|asetpts|atrim|concat|drawtext|fade|format|metadata|movie|overlay|scale|sendcmd|setpts|subtitles|trim|xfade|zoompan)\s*=/',
        $exceptions,
        ['Slate/Driver/'],
    );
}

/**
 * Finds input-bearing filter sources inside the trusted driver compiler boundary.
 *
 * The concat filter's compiler-owned `n=` form joins already-typed inputs and is not an input source.
 *
 * @return list<string>
 */
function ffmpegDriverInputFilterFragmentLocations(string $root): array
{
    return sourcePatternLocations(
        $root,
        '/(?:(?<=[\'",])|(?<=\n)\h*)(?:(?:amovie|movie)\s*=|concat\s*=(?!n\s*=))/',
    );
}

/**
 * Finds drawtext `text=` emitters inside the compiler boundary.
 *
 * The caller-layer filter scan deliberately excludes Slate/Driver, so this exact-location scan ensures
 * a second compiler emitter cannot omit the escaping and `expansion=none` contract unnoticed. It is a
 * source-text scan and cannot detect an emitter assembled from split strings at runtime.
 *
 * @return array<string, int>
 */
function drawtextEmitterLocations(string $root): array
{
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        $count = preg_match_all('/(?<=[\'",\n])[^\r\n]*?\btext\s*=/', $contents);

        if ($count < 1) {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );
        $locations[$relativePath] = $count;
    }

    ksort($locations);

    return $locations;
}

/**
 * Finds expression-option emitters so their provenance cannot spread to a caller-facing class.
 *
 * @return array<string, array<string, int>>
 */
function drawtextExpressionEmitterLocations(string $root): array
{
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        if (preg_match_all('/\b(alpha|enable|x|y)=/', $contents, $matches) < 1) {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        foreach ($matches[1] as $option) {
            $locations[$relativePath][$option] = ($locations[$relativePath][$option] ?? 0) + 1;
        }

        ksort($locations[$relativePath]);
    }

    ksort($locations);

    return $locations;
}

/** @return list<class-string<Operation>> */
function operationImplementationClasses(string $root): array
{
    $classes = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = substr($file->getPathname(), strlen($root) + 1, -4);
        $class = 'App\\Slate\\Recipe\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

        if (class_exists($class) && is_subclass_of($class, Operation::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/** @return list<string> */
function allowedOperationPublicStringProperties(): array
{
    return [];
}

/**
 * Finds direct S3 client, disk, or object-URL construction above Slate's storage boundary.
 *
 * This source-text scan recognizes fully qualified and imported AWS S3 clients, direct or configured
 * S3ObjectStorage construction, and Laravel Storage facade disk/build/drive/cloud/URL calls. It cannot
 * detect aliased classes or dynamic method names; the storage interface and code review cover that residue.
 *
 * @return array<string, list<string>>
 */
function storageBoundaryLocations(string $root): array
{
    $patterns = [
        'configured object storage construction' => '/S3ObjectStorage::fromConfiguredDisk\s*\(/',
        'fully qualified S3 client construction' => '/new\s+\\\\?Aws\\\\S3\\\\S3Client\s*\(/',
        'imported S3 client construction' => '/new\s+S3Client\s*\(/',
        'object storage construction' => '/new\s+S3ObjectStorage\s*\(/',
        'storage build' => '/Storage::build\s*\(/',
        'storage cloud' => '/Storage::cloud\s*\(/',
        'storage disk' => '/Storage::disk\s*\(/',
        'storage drive' => '/Storage::drive\s*\(/',
        'storage temporary url' => '/Storage::temporaryUrl\s*\(/',
        'storage url' => '/Storage::url\s*\(/',
    ];
    $locations = [];

    foreach ($patterns as $category => $pattern) {
        $matches = sourcePatternLocations($root, $pattern, excludedPrefixes: ['Slate/Storage/']);

        if ($matches !== []) {
            $locations[$category] = $matches;
        }
    }

    ksort($locations);

    return $locations;
}

/**
 * @param  list<string>  $exceptions
 * @param  list<string>  $excludedPrefixes
 * @return list<string>
 */
function sourcePatternLocations(
    string $root,
    string $pattern,
    array $exceptions = [],
    array $excludedPrefixes = [],
): array {
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($root) + 1),
        );

        if (in_array($relativePath, $exceptions, true)
            || array_any($excludedPrefixes, fn (string $prefix): bool => str_starts_with($relativePath, $prefix))) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        if (preg_match($pattern, $contents) === 1) {
            $locations[] = $relativePath;
        }
    }

    sort($locations);

    return $locations;
}

/**
 * Inspects the object graph Laravel serializes for a queued job and rejects scratch objects plus
 * recognizable absolute filesystem paths. Relative path strings are intentionally outside this
 * detector; typed payload construction and the resolved-adapter guard cover that residue.
 *
 * @return list<string>
 */
function queuedJobAbsolutePathViolations(object $job): array
{
    $violations = [];
    $seen = [];
    $walk = function (mixed $value, string $path) use (&$walk, &$seen, &$violations): void {
        if (is_string($value)) {
            if (preg_match('~^(?:/|[A-Za-z]:[\\\\/]|file://)~', $value) === 1) {
                $violations[] = "{$path} contains host-local path {$value}";
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $walk($item, $path.'['.(string) $key.']');
            }

            return;
        }

        if (! is_object($value) || $value instanceof UnitEnum) {
            return;
        }

        if ($value instanceof ScratchPath || $value instanceof ScratchDirectory) {
            $violations[] = "{$path} contains ".$value::class;

            return;
        }

        $objectId = spl_object_id($value);

        if (isset($seen[$objectId])) {
            return;
        }

        $seen[$objectId] = true;

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($value)) {
                continue;
            }

            $walk($property->getValue($value), $path.'::$'.$property->getName());
        }
    };
    $walk(unserialize(serialize($job)), $job::class);

    sort($violations);

    return $violations;
}

/**
 * Rejects compiler products and selected binary, encoder, and filter vocabulary from Laravel's
 * serialized job graph. A filterless argv made entirely from relative strings is not detectable by
 * this vocabulary scan; typed job properties and review cover that residue.
 *
 * @return list<string>
 */
function queuedJobKnownCompiledVocabularyViolations(object $job): array
{
    return knownCompiledVocabularyViolations(unserialize(serialize($job)), $job::class);
}

/**
 * Recursively rejects compiler products, command-shaped lists, command keys, and Slate's complete
 * recognized encoder and filter vocabularies. Unknown future option/filter/encoder names remain
 * outside this detector and require updating the closed vocabularies when introduced.
 *
 * @return list<string>
 */
function knownCompiledVocabularyViolations(mixed $payload, string $root = 'payload'): array
{
    $violations = [];
    $seen = [];
    $compiledTypes = [
        CompiledRecipe::class,
        CompiledArgument::class,
        CompiledFilterGraph::class,
        CompiledInput::class,
        CompiledOutput::class,
    ];
    $walk = function (mixed $value, string $path) use (&$walk, &$seen, &$violations, $compiledTypes): void {
        if (is_string($value)) {
            if (preg_match('/\b(?:ffmpeg|ffprobe)\b/i', $value) === 1
                || preg_match(recognizedEncoderPattern(), $value) === 1) {
                $violations[] = "{$path} contains binary or encoder vocabulary";
            }

            $filterPattern = '/(?:-filter_complex|(?:'.implode('|', recognizedFilterNames()).')\s*=)/';

            if (preg_match($filterPattern, $value) === 1) {
                $violations[] = "{$path} contains compiled filter vocabulary";
            }

            return;
        }

        if (is_array($value)) {
            if (array_is_list($value)
                && count($value) > 1
                && array_any($value, static fn (mixed $item): bool => is_string($item) && str_starts_with($item, '-'))) {
                $violations[] = "{$path} contains command-shaped positional arguments";
            }

            foreach ($value as $key => $item) {
                if (is_string($key)
                    && preg_match('/(?:^|_)(?:argv|arguments?|command|encoder|filter(?:graph)?)(?:_|$)/i', $key) === 1) {
                    $violations[] = "{$path}[{$key}] contains a compiled-command key";
                }

                $walk($item, $path.'['.(string) $key.']');
            }

            return;
        }

        if (! is_object($value) || $value instanceof UnitEnum) {
            return;
        }

        foreach ($compiledTypes as $compiledType) {
            if ($value instanceof $compiledType) {
                $violations[] = "{$path} contains compiled type {$compiledType}";

                return;
            }
        }

        $objectId = spl_object_id($value);

        if (isset($seen[$objectId])) {
            return;
        }

        $seen[$objectId] = true;

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($value)) {
                continue;
            }

            $walk($property->getValue($value), $path.'::$'.$property->getName());
        }
    };
    $walk($payload, $root);

    sort($violations);

    return $violations;
}

/**
 * Finds direct positive integer assignments to known visibility-window identifier spellings.
 *
 * The token scan ignores comments and strings and accepts PHP numeric separators. It normalizes
 * camelCase and snake_case forms of visibilityWindow, visibilityTimeout, and retryAfter. It cannot
 * detect computed values, dynamically assembled identifiers, differently named concepts,
 * constant-string configuration keys, or floating-point literals.
 *
 * @return list<string>
 */
function visibilityWindowNumberLocations(string $root): array
{
    $locations = [];
    $recognizedNames = [
        'retryafter',
        'visibilitytimeout',
        'visibilitytimeoutseconds',
        'visibilitywindow',
        'visibilitywindowseconds',
    ];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents)) {
            throw new RuntimeException("Unable to scan source file: {$file->getPathname()}");
        }

        $tokens = token_get_all($contents);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_VARIABLE, T_STRING], true)) {
                continue;
            }

            $name = strtolower(str_replace('_', '', ltrim($token[1], '$')));

            if (! in_array($name, $recognizedNames, true)) {
                continue;
            }

            $assignment = nextSignificantToken($tokens, $index);

            if ($assignment['token'] !== '=') {
                continue;
            }

            $value = nextSignificantToken($tokens, $assignment['index']);

            if (is_array($value['token']) && $value['token'][0] === T_LNUMBER) {
                $locations[] = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($file->getPathname(), strlen($root) + 1),
                );
                break;
            }
        }
    }

    sort($locations);

    return $locations;
}

/**
 * Finds autoloadable ShouldQueue classes carrying an integer default for timeout.
 *
 * Reflection resolves qualified and aliased interfaces plus inherited and trait-borne defaults, while
 * tokenized class discovery ignores comments and strings. It cannot inspect generated/evaluated classes,
 * classes that cannot be autoloaded, or constructor assignments because those are not bare defaults.
 *
 * @return list<string>
 */
function bareQueuedJobTimeoutLocations(string $root): array
{
    $locations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (declaredClassNames($file->getPathname()) as $class) {
            if (! is_a($class, ShouldQueue::class, true)) {
                continue;
            }

            $defaults = (new ReflectionClass($class))->getDefaultProperties();

            if (isset($defaults['timeout']) && is_int($defaults['timeout'])) {
                $locations[] = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($file->getPathname(), strlen($root) + 1),
                );
                break;
            }
        }
    }

    sort($locations);

    return $locations;
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 * @return array{index: int, token: array{int, string, int}|string|null}
 */
function nextSignificantToken(array $tokens, int $index): array
{
    for ($next = $index + 1; $next < count($tokens); $next++) {
        $token = $tokens[$next];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return ['index' => $next, 'token' => $token];
    }

    return ['index' => count($tokens), 'token' => null];
}

/** @return list<class-string> */
function declaredClassNames(string $path): array
{
    $contents = file_get_contents($path);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to scan source file: {$path}");
    }

    $tokens = token_get_all($contents);
    $namespace = '';
    $classes = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = '';

            for ($next = $index + 1; $next < count($tokens); $next++) {
                $part = $tokens[$next];

                if (is_string($part) && in_array($part, [';', '{'], true)) {
                    break;
                }

                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $part[1];
                }
            }

            continue;
        }

        if ($token[0] !== T_CLASS) {
            continue;
        }

        $previous = $index > 0 ? nextSignificantToken(array_reverse(array_slice($tokens, 0, $index)), -1)['token'] : null;

        if (is_array($previous) && $previous[0] === T_NEW) {
            continue;
        }

        $name = nextSignificantToken($tokens, $index)['token'];

        if (is_array($name) && $name[0] === T_STRING) {
            $classes[] = ltrim($namespace.'\\'.$name[1], '\\');
        }
    }

    return $classes;
}
