<?php

declare(strict_types=1);

namespace App\Slate\Driver\Binary;

use App\Slate\Storage\ScratchPath;

/** An atomic input unit whose argv shape is bound to ffmpeg or ffprobe. */
final readonly class CompiledInput
{
    /** @param  non-empty-list<string>  $commandValues */
    private function __construct(
        private array $commandValues,
        private BinaryExecutable $executable,
    ) {}

    public static function ffmpeg(ScratchPath $input): self
    {
        return new self(['-i', $input->readablePath()], BinaryExecutable::Ffmpeg);
    }

    public static function lavfi(LavfiInput $input): self
    {
        return new self(['-i', $input->value], BinaryExecutable::Ffmpeg);
    }

    public static function ffprobe(ScratchPath $input): self
    {
        return new self([$input->readablePath()], BinaryExecutable::Ffprobe);
    }

    /** @return non-empty-list<string> */
    public function commandValues(): array
    {
        return $this->commandValues;
    }

    public function supports(BinaryExecutable $executable): bool
    {
        return $this->executable === $executable;
    }
}
