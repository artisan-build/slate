<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use App\Models\RunStatus;
use App\Models\RunTrust;
use App\Models\SourceStatus;
use App\Slate\Input\InputRejectionReason;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Run\RunApiErrorReason;
use App\Slate\Source\SourceApiErrorReason;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ApiDocumentation\UndocumentedWireValue;
use Tests\Support\ApiDocumentationContract;
use Tests\TestCase;

/**
 * This presence check cannot prove that the prose or examples are correct, only that router-derived
 * endpoint signatures and reflection-derived enum values have corresponding text in the API document.
 */
final class ApiDocumentationTest extends TestCase
{
    /** @var list<class-string<\BackedEnum>> */
    private const array WireEnums = [
        RunStatus::class,
        RunTrust::class,
        SourceStatus::class,
        InputRejectionReason::class,
        QualityTier::class,
        VideoTransition::class,
        RunApiErrorReason::class,
        SourceApiErrorReason::class,
    ];

    public function test_api_documentation_contains_every_router_route_and_wire_enum_case(): void
    {
        $documentation = $this->documentation();

        self::assertSame([], ApiDocumentationContract::missingRoutes(Route::getRoutes(), $documentation));
        self::assertSame([], ApiDocumentationContract::missingEnumCases(self::WireEnums, $documentation));
    }

    public function test_route_positive_control_detects_a_router_route_absent_from_the_document(): void
    {
        Route::get('/api/v1/documentation-positive-control', static fn (): string => 'control');

        self::assertSame(
            ['GET /api/v1/documentation-positive-control'],
            ApiDocumentationContract::missingRoutes(Route::getRoutes(), $this->documentation()),
        );
    }

    public function test_enum_positive_control_detects_a_reflected_case_absent_from_the_document(): void
    {
        self::assertSame(
            [UndocumentedWireValue::class.'::PositiveControl=documentation_positive_control'],
            ApiDocumentationContract::missingEnumCases(
                [...self::WireEnums, UndocumentedWireValue::class],
                $this->documentation(),
            ),
        );
    }

    private function documentation(): string
    {
        $documentation = file_get_contents(base_path('docs/API.md'));

        self::assertIsString($documentation);

        return $documentation;
    }
}
