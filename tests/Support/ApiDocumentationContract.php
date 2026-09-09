<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use Illuminate\Routing\RouteCollectionInterface;
use ReflectionEnum;
use ReflectionEnumBackedCase;

final class ApiDocumentationContract
{
    /** @return list<string> */
    public static function missingRoutes(RouteCollectionInterface $routes, string $documentation): array
    {
        $missing = [];

        foreach ($routes->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $signature = $method.' /'.$route->uri();

                if (! str_contains($documentation, $signature)) {
                    $missing[] = $signature;
                }
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * @param  list<class-string<BackedEnum>>  $enumClasses
     * @return list<string>
     */
    public static function missingEnumCases(array $enumClasses, string $documentation): array
    {
        $missing = [];

        foreach ($enumClasses as $enumClass) {
            $reflection = new ReflectionEnum($enumClass);

            foreach ($reflection->getCases() as $case) {
                if (! $case instanceof ReflectionEnumBackedCase) {
                    continue;
                }

                $value = (string) $case->getBackingValue();

                if (! str_contains($documentation, '`'.$value.'`')) {
                    $missing[] = $enumClass.'::'.$case->getName().'='.$value;
                }
            }
        }

        sort($missing);

        return $missing;
    }
}
