<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;

/*
|--------------------------------------------------------------------------
| Landing manifest URLs
|--------------------------------------------------------------------------
|
| Every URL in `config/built-for-cloud.manifest` is rendered to anonymous
| visitors — the icon as an `<img src>` and the product URL as an `<a href>`,
| on the landing page and again on `/bfc/ui`. So every one of them has to
| resolve publicly, or every deployed instance shows a broken image and a dead
| link. Issue #37: `icon` pointed at https://scalpels.app/icons/slate.svg and
| `product_url` at https://scalpels.app/products/slate, and both answered 404.
|
| The invariant is "the manifest publishes only URLs that resolve", but a test
| that fetches them would make CI depend on scalpels.app being reachable. So the
| URLs are pinned instead: each list below was checked with curl on 2026-09-23,
| and adding a URL to the resolving list means checking it the same way.
|
*/

/**
 * scalpels.app URLs verified to answer HTTP 200 on 2026-09-23.
 *
 * @return array<int, string>
 */
function resolvingScalpelsUrls(): array
{
    return [
        'https://scalpels.app',
        'https://scalpels.app/img/products/transparent/slate.svg',
        'https://scalpels.app/img/products/transparent/slate.png',
    ];
}

/**
 * scalpels.app URLs verified to answer HTTP 404 on 2026-09-23.
 *
 * @return array<int, string>
 */
function missingScalpelsUrls(): array
{
    return [
        'https://scalpels.app/icons/slate.svg',
        'https://scalpels.app/products/slate',
    ];
}

/**
 * Every URL the landing manifest publishes, keyed by its configuration key.
 *
 * @return array<string, string>
 */
function landingManifestUrls(): array
{
    $manifest = LandingManifest::fromConfiguration();

    return [
        'icon' => $manifest->icon,
        'product_url' => $manifest->productUrl,
    ];
}

it('publishes only scalpels.app URLs that are known to resolve', function (): void {
    foreach (landingManifestUrls() as $key => $url) {
        expect($url)->toBeIn(
            resolvingScalpelsUrls(),
            "The landing manifest [{$key}] is [{$url}], which is not a scalpels.app URL anyone has verified resolves. Every manifest URL is rendered to anonymous visitors.",
        );
    }
});

it('never publishes a scalpels.app URL that is known to 404', function (): void {
    foreach (landingManifestUrls() as $key => $url) {
        expect(in_array($url, missingScalpelsUrls(), true))->toBeFalse(
            "The landing manifest [{$key}] is [{$url}], which answers HTTP 404. Visitors get a broken image or a dead link.",
        );
    }
});

it('renders only resolving scalpels.app URLs on the landing page', function (): void {
    $content = $this->get('/')->assertOk()->getContent();

    expect($content)->toBeString();

    preg_match_all('#https://scalpels\.app[^\s"\'<>]*#', $content, $matches);

    $rendered = array_values(array_unique($matches[0]));

    expect($rendered)->not->toBeEmpty('The landing page rendered no scalpels.app URL at all.');

    foreach ($rendered as $url) {
        expect($url)->toBeIn(
            resolvingScalpelsUrls(),
            "The landing page renders [{$url}], which is not a scalpels.app URL anyone has verified resolves.",
        );
    }
});
