<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class ClosedInputTool extends Tool
{
    use AdvertisesToolEffect, RespectsEffectCeiling;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $declaredClassification = ToolClassification::of($this);
        $declaredEffect = ToolEffect::of($this);
        $classification = $declaredClassification === null ? Classification::Content : $declaredClassification->value;
        $effect = $declaredEffect === null ? Effect::Destructive : $declaredEffect->value;
        $this->setMeta(ToolClassification::META_KEY, $classification->value);
        $this->setMeta(ToolEffect::META_KEY, $effect->value);

        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    /** @param list<string> $expectedKeys */
    protected function hasExactArguments(Request $request, array $expectedKeys): bool
    {
        $actualKeys = array_keys($request->all());

        if (array_any($actualKeys, static fn (mixed $key): bool => ! is_string($key))) {
            return false;
        }

        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);

        return $actualKeys === $expectedKeys;
    }
}
