<?php

use App\Slate\Recipe\QualityTier;

test('accepts caller-facing tier values through the backed enum contract', function (): void {
    expect(QualityTier::tryFrom('delivery'))->toBe(QualityTier::Delivery)
        ->and(QualityTier::tryFrom('intermediate'))->toBe(QualityTier::Intermediate)
        ->and(QualityTier::tryFrom('unknown'))->toBeNull();
});
