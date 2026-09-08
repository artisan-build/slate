<?php

declare(strict_types=1);

namespace App\Slate\Source;

use App\Models\Source;
use App\Slate\Driver\Binary\RecipeCompiler;
use App\Slate\Driver\Driver;
use App\Slate\Input\InputAdmission;
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
            $this->inputAdmission->inspectVideo($input, $this->compiler, $this->driver);
        });
    }
}
