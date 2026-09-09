<?php

declare(strict_types=1);

namespace Tests\Feature\Documentation;

use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ApiDocumentation\UndocumentedWireValue;
use Tests\Support\ApiDocumentationContract;
use Tests\TestCase;

/**
 * This presence check cannot prove that prose or examples are correct. It discovers application enums
 * explicitly marked as wire-facing, but cannot identify an enum that should carry the marker and does not.
 */
final class ApiDocumentationTest extends TestCase
{
    public function test_api_documentation_contains_every_router_route_and_wire_enum_case(): void
    {
        $documentation = $this->documentation();

        self::assertSame([], ApiDocumentationContract::missingRoutes(Route::getRoutes(), $documentation));
        self::assertSame([], ApiDocumentationContract::missingEnumCases(
            ApiDocumentationContract::discoverWireEnums(app_path()),
            $documentation,
        ));
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
        $discovered = ApiDocumentationContract::discoverWireEnums(base_path('tests/Fixtures/ApiDocumentation'));

        self::assertContains(UndocumentedWireValue::class, $discovered);
        self::assertSame(
            [UndocumentedWireValue::class.'::PositiveControl=documentation_positive_control'],
            ApiDocumentationContract::missingEnumCases($discovered, $this->documentation()),
        );
    }

    private function documentation(): string
    {
        $documentation = file_get_contents(base_path('docs/API.md'));

        self::assertIsString($documentation);

        return $documentation;
    }
}
