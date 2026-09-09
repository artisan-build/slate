<?php

declare(strict_types=1);

namespace App\Slate\Input;

use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Ingest\SourceMetadata;
use App\Slate\Recipe\Probe;
use App\Slate\Storage\ScratchPath;
use JsonException;
use Psr\Http\Message\StreamInterface;

final readonly class InputAdmission
{
    public function assertSize(int $bytes): void
    {
        $maximumBytes = config('slate.ingest.maximum_source_bytes');

        if (! is_int($maximumBytes) || $maximumBytes < 1) {
            throw new InputRejectedException(
                InputRejectionReason::SourceTooLarge,
                'The configured source size limit is invalid.',
            );
        }

        if ($bytes > $maximumBytes) {
            throw new InputRejectedException(
                InputRejectionReason::SourceTooLarge,
                "The source exceeds the {$maximumBytes}-byte limit.",
            );
        }
    }

    public function inspectSourceMetadata(SourceMetadata $metadata): void
    {
        $this->assertSize($metadata->bytes);
    }

    public function inspectStream(StreamInterface $stream, bool $requireContainerMagic): void
    {
        if (! $stream->isSeekable()) {
            throw new InputRejectedException(
                InputRejectionReason::UnsupportedContainer,
                'Input admission requires a seekable byte stream.',
            );
        }

        $position = $stream->tell();
        $stream->seek(0);
        $bytes = $stream->getContents();
        $stream->seek($position);

        $this->assertNoReferences($bytes);

        if ($requireContainerMagic) {
            $this->assertSelfContainedContainer($bytes);
            $this->assertNoReferenceAtomsInBytes($bytes);
        }
    }

    public function inspectVideo(
        ScratchPath $input,
        RecipeCompiler $compiler,
        Driver $driver,
    ): InputMediaProperties {
        $prefix = $this->admittedPrefix($input);
        $this->assertSelfContainedContainer($prefix);
        $properties = $this->probeProperties($input, $compiler, $driver);

        return $this->assertStructuralCaps($properties, requireVideo: true, requireAudio: false);
    }

    public function inspectAudio(ScratchPath $input, RecipeCompiler $compiler, Driver $driver): void
    {
        $prefix = $this->admittedPrefix($input);
        $this->assertSelfContainedContainer($prefix);
        $properties = $this->probeProperties($input, $compiler, $driver);
        $this->assertStructuralCaps($properties, requireVideo: false, requireAudio: true);
    }

    public function inspectContentSafety(ScratchPath $input): void
    {
        $this->admittedPrefix($input);
    }

    public function inspectStillImage(ScratchPath $input): void
    {
        $prefix = $this->admittedPrefix($input);
        $isPng = str_starts_with($prefix, "\x89PNG\r\n\x1A\n");
        $isJpeg = str_starts_with($prefix, "\xFF\xD8\xFF");

        if (! $isPng && ! $isJpeg) {
            throw new InputRejectedException(
                InputRejectionReason::UnsupportedContainer,
                'Still-image bytes must be PNG or JPEG.',
            );
        }

        $properties = @getimagesize($input->readablePath());

        if (! is_array($properties)
            || ! in_array($properties[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new InputRejectedException(InputRejectionReason::UnsupportedContainer, 'The still image is malformed.');
        }

        $width = $properties[0];
        $height = $properties[1];

        if ($width < 1 || $height < 1
            || $width > $this->positiveConfigInteger('slate.input.maximum_width')
            || $height > $this->positiveConfigInteger('slate.input.maximum_height')) {
            throw new InputRejectedException(InputRejectionReason::ResolutionExceeded, 'The image resolution exceeds the admitted limit.');
        }
    }

    public function inspectFont(ScratchPath $input): void
    {
        $prefix = $this->admittedPrefix($input);

        if (! str_starts_with($prefix, "\x00\x01\x00\x00")
            && ! str_starts_with($prefix, 'OTTO')
            && ! str_starts_with($prefix, 'true')
            && ! str_starts_with($prefix, 'ttcf')) {
            throw new InputRejectedException(
                InputRejectionReason::UnsupportedContainer,
                'Font bytes do not match an admitted sfnt signature.',
            );
        }
    }

    private function admittedPrefix(ScratchPath $input): string
    {
        $size = filesize($input->readablePath());

        if (! is_int($size) || $size < 1) {
            throw new InputRejectedException(InputRejectionReason::UnsupportedContainer, 'The staged input is empty or unreadable.');
        }

        $this->assertSize($size);
        $stream = fopen($input->readablePath(), 'rb');

        if ($stream === false) {
            throw new InputRejectedException(InputRejectionReason::UnsupportedContainer, 'The staged input cannot be opened for admission.');
        }

        try {
            $prefix = fread($stream, 64 * 1024);

            if (! is_string($prefix)) {
                throw new InputRejectedException(InputRejectionReason::UnsupportedContainer, 'The staged input cannot be read for admission.');
            }

            $this->assertNoReferences($prefix);
            $this->assertNoReferenceAtomsInFile($stream, $size);

            return $prefix;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<mixed> */
    private function probeProperties(ScratchPath $input, RecipeCompiler $compiler, Driver $driver): array
    {
        $probe = $driver->run($compiler->compile(new Probe($input)));

        if ($probe->failed()) {
            throw new InputRejectedException(InputRejectionReason::ProbeFailed, 'The input media probe failed.');
        }

        try {
            $properties = json_decode($probe->output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InputRejectedException(InputRejectionReason::ProbeFailed, 'The input media probe returned invalid JSON.');
        }

        if (! is_array($properties)) {
            throw new InputRejectedException(InputRejectionReason::ProbeFailed, 'The input media probe returned an invalid payload.');
        }

        return $properties;
    }

    /** @param array<mixed> $properties */
    private function assertStructuralCaps(
        array $properties,
        bool $requireVideo,
        bool $requireAudio,
    ): InputMediaProperties {
        $streams = $properties['streams'] ?? null;
        $duration = $properties['format']['duration'] ?? null;

        if (! is_array($streams) || ! array_is_list($streams) || ! is_numeric($duration) || (float) $duration <= 0) {
            throw new InputRejectedException(InputRejectionReason::ProbeFailed, 'The input probe omitted required media structure.');
        }

        $maximumStreams = $this->positiveConfigInteger('slate.input.maximum_streams');

        if (count($streams) > $maximumStreams) {
            throw new InputRejectedException(InputRejectionReason::StreamCountExceeded, 'The input contains too many media streams.');
        }

        $durationSeconds = (float) $duration;

        if ($durationSeconds > $this->positiveConfigInteger('slate.input.maximum_duration_seconds')) {
            throw new InputRejectedException(InputRejectionReason::DurationExceeded, 'The input duration exceeds the admitted limit.');
        }

        $allowedCodecs = config('slate.input.decode_codecs');

        if (! is_array($allowedCodecs) || ! array_is_list($allowedCodecs)
            || array_any($allowedCodecs, static fn (mixed $codec): bool => ! is_string($codec) || $codec === '')) {
            throw new InputRejectedException(InputRejectionReason::DecodeCodecNotAllowed, 'The decode codec allowlist is invalid.');
        }

        $videoStreams = [];
        $hasAudio = false;

        foreach ($streams as $stream) {
            if (! is_array($stream)
                || ! in_array($stream['codec_type'] ?? null, ['video', 'audio'], true)
                || ! is_string($stream['codec_name'] ?? null)
                || ! in_array($stream['codec_name'], $allowedCodecs, true)) {
                throw new InputRejectedException(InputRejectionReason::DecodeCodecNotAllowed, 'The input requires a decoder outside the allowlist.');
            }

            if ($stream['codec_type'] === 'video') {
                $videoStreams[] = $stream;
            }

            $hasAudio = $hasAudio || $stream['codec_type'] === 'audio';
        }

        if ($requireVideo && $videoStreams === []) {
            throw new InputRejectedException(InputRejectionReason::VideoStreamMissing, 'A video clip source requires a video stream.');
        }

        if ($requireAudio && ! $hasAudio) {
            throw new InputRejectedException(InputRejectionReason::AudioStreamMissing, 'An audio source requires an audio stream.');
        }

        $primaryWidth = null;
        $primaryHeight = null;
        $primaryFrameRate = null;

        foreach ($videoStreams as $videoStream) {
            $width = $videoStream['width'] ?? null;
            $height = $videoStream['height'] ?? null;

            if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
                || $width > $this->positiveConfigInteger('slate.input.maximum_width')
                || $height > $this->positiveConfigInteger('slate.input.maximum_height')) {
                throw new InputRejectedException(InputRejectionReason::ResolutionExceeded, 'The input resolution exceeds the admitted limit.');
            }

            $framesPerSecond = $this->frameRate($videoStream['avg_frame_rate'] ?? null);

            if ($framesPerSecond <= 0 || $framesPerSecond > $this->positiveConfigInteger('slate.input.maximum_frame_rate')) {
                throw new InputRejectedException(InputRejectionReason::FrameRateExceeded, 'The input frame rate exceeds the admitted limit.');
            }

            $primaryWidth ??= $width;
            $primaryHeight ??= $height;
            $primaryFrameRate ??= $framesPerSecond;
        }

        return new InputMediaProperties(
            $durationSeconds,
            $primaryWidth ?? 1,
            $primaryHeight ?? 1,
            $primaryFrameRate ?? 1.0,
            count($streams),
            $hasAudio,
        );
    }

    private function assertNoReferences(string $bytes): void
    {
        $prefix = strtolower(ltrim(substr($bytes, 0, 4096)));

        if (str_starts_with($prefix, '#extm3u')
            || str_starts_with($prefix, 'ffconcat')
            || preg_match('/^<\?xml[^>]*>\s*<mpd\b|^<mpd\b/i', $prefix) === 1) {
            throw new InputRejectedException(
                InputRejectionReason::ReferencedMedia,
                'Playlist and reference media inputs are not admitted.',
            );
        }

    }

    private function assertNoReferenceAtomsInBytes(string $bytes): void
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new InputRejectedException(InputRejectionReason::UnsupportedContainer, 'Unable to inspect input container atoms.');
        }

        try {
            fwrite($stream, $bytes);
            $this->assertNoReferenceAtomsInFile($stream, strlen($bytes));
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function assertNoReferenceAtomsInFile($stream, int $bytes): void
    {
        if ($this->containsReferenceAtom($stream, 0, $bytes)) {
            throw new InputRejectedException(
                InputRejectionReason::ReferencedMedia,
                'QuickTime reference movies are not admitted.',
            );
        }
    }

    /** @param resource $stream */
    private function containsReferenceAtom($stream, int $start, int $end): bool
    {
        $containerTypes = ['dinf', 'mdia', 'minf', 'moov', 'rmda', 'rmra', 'stbl', 'trak'];
        $offset = $start;

        while ($offset + 8 <= $end) {
            if (fseek($stream, $offset) !== 0) {
                return false;
            }

            $header = fread($stream, 8);

            if (! is_string($header) || strlen($header) !== 8) {
                return false;
            }

            $size = unpack('Nsize', substr($header, 0, 4));
            $atomSize = is_array($size) ? $size['size'] : 0;
            $atomType = substr($header, 4, 4);
            $headerBytes = 8;

            if ($atomSize === 1) {
                $extended = fread($stream, 8);

                if (! is_string($extended) || strlen($extended) !== 8) {
                    return false;
                }

                $parts = unpack('Nhigh/Nlow', $extended);
                $atomSize = is_array($parts)
                    ? ($parts['high'] * 4_294_967_296) + $parts['low']
                    : 0;
                $headerBytes = 16;
            } elseif ($atomSize === 0) {
                $atomSize = $end - $offset;
            }

            if ($atomSize < $headerBytes || $offset + $atomSize > $end) {
                return false;
            }

            if (in_array($atomType, ['rmra', 'rdrf'], true)) {
                return true;
            }

            if (in_array($atomType, $containerTypes, true)
                && $this->containsReferenceAtom($stream, $offset + $headerBytes, $offset + $atomSize)) {
                return true;
            }

            $offset += $atomSize;
        }

        return false;
    }

    private function assertSelfContainedContainer(string $bytes): void
    {
        $isIsoBaseMedia = strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp';
        $isMatroska = str_starts_with($bytes, "\x1A\x45\xDF\xA3");
        $isWave = str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE';
        $isMp3 = str_starts_with($bytes, 'ID3')
            || (strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0);
        $isAac = strlen($bytes) >= 2 && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xF6) === 0xF0;

        if (! $isIsoBaseMedia && ! $isMatroska && ! $isWave && ! $isMp3 && ! $isAac) {
            throw new InputRejectedException(
                InputRejectionReason::UnsupportedContainer,
                'Input bytes do not match an admitted self-contained media container.',
            );
        }
    }

    private function positiveConfigInteger(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value < 1) {
            throw new InputRejectedException(InputRejectionReason::ProbeFailed, "Input limit [{$key}] is invalid.");
        }

        return $value;
    }

    private function frameRate(mixed $value): float
    {
        if (! is_string($value) || preg_match('/^(\d+)\/(\d+)$/', $value, $matches) !== 1) {
            return 0.0;
        }

        $denominator = (int) $matches[2];

        return $denominator === 0 ? 0.0 : (int) $matches[1] / $denominator;
    }
}
