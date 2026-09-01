<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Slate\Driver\Binary\H264RateControl;
use App\Slate\Recipe\QualityTier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slate:h264-arguments {width} {height} {fps} {--tier=} {--bits-per-pixel=}')]
#[Description('Print the shipped H.264 output arguments, one argument per line.')]
final class H264ArgumentsCommand extends Command
{
    public function handle(): int
    {
        $width = (int) $this->argument('width');
        $height = (int) $this->argument('height');
        $framesPerSecond = (int) $this->argument('fps');
        $tierName = $this->option('tier');
        $bitsPerPixelOption = $this->option('bits-per-pixel');

        if ($width < 1 || $height < 1 || $framesPerSecond < 1) {
            $this->components->error('Width, height, and fps must be positive integers.');

            return self::FAILURE;
        }

        if (is_string($tierName) === is_string($bitsPerPixelOption)) {
            $this->components->error('Provide exactly one of --tier or --bits-per-pixel.');

            return self::FAILURE;
        }

        if (is_string($tierName)) {
            $tier = QualityTier::tryFrom($tierName);

            if ($tier === null) {
                $this->components->error("Unknown quality tier [{$tierName}].");

                return self::FAILURE;
            }

            $arguments = H264RateControl::argumentsFor($tier, $width, $height, $framesPerSecond);
        } else {
            if (! is_string($bitsPerPixelOption) || ! is_numeric($bitsPerPixelOption) || (float) $bitsPerPixelOption <= 0) {
                $this->components->error('Bits per pixel must be a positive number.');

                return self::FAILURE;
            }

            $arguments = H264RateControl::argumentsForBitsPerPixel(
                (float) $bitsPerPixelOption,
                $width,
                $height,
                $framesPerSecond,
            );
        }

        foreach ($arguments as $argument) {
            $this->line($argument);
        }

        return self::SUCCESS;
    }
}
