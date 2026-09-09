<?php

declare(strict_types=1);

namespace App\Slate\Source;

use App\Models\Source;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Input\InputAdmission;
use App\Slate\Input\InputRejectedException;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Storage\IngestStorage;
use App\Slate\Storage\JobScratch;
use App\Slate\Storage\ObjectStorage;
use App\Slate\Storage\ScratchDirectory;

final readonly class SourceAdmission
{
    public function __construct(
        private IngestStorage $ingestStorage,
        private ObjectStorage $objectStorage,
        private JobScratch $jobScratch,
        private InputAdmission $inputAdmission,
        private RecipeCompiler $compiler,
        private Driver $driver,
    ) {}

    public function handle(Source $source): void
    {
        $source->assertStoredBytes($this->ingestStorage->size($source->objectKey()));
        $this->inputAdmission->assertSize($source->bytes);

        $this->jobScratch->run(function (ScratchDirectory $scratch) use ($source): void {
            $input = $scratch->file('source');
            $this->objectStorage->stageIn($source->objectKey(), $input);
            $contentType = strtolower(trim(explode(';', $source->content_type ?? '', 2)[0]));
            $this->inputAdmission->inspectContentSafety($input);

            match (true) {
                str_starts_with($contentType, 'video/') => $this->inputAdmission->inspectVideo($input, $this->compiler, $this->driver),
                str_starts_with($contentType, 'audio/') => $this->inputAdmission->inspectAudio($input, $this->compiler, $this->driver),
                in_array($contentType, ['image/jpeg', 'image/png'], true) => $this->inputAdmission->inspectStillImage($input),
                str_starts_with($contentType, 'font/'),
                in_array($contentType, ['application/font-sfnt', 'application/vnd.ms-opentype', 'application/x-font-ttf'], true) => $this->inputAdmission->inspectFont($input),
                default => throw new InputRejectedException(
                    InputRejectionReason::UnsupportedContainer,
                    'The declared source content type is not admitted.',
                ),
            };
        });
    }
}
