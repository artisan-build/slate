<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;

/*
|--------------------------------------------------------------------------
| Landing manifest URLs
|--------------------------------------------------------------------------
|
| `GET /` renders the landing manifest to anonymous visitors: the icon as the
| `src` of `landing-manifest-icon`, the product URL as the `href` of
| `landing-manifest-product-link`. (`/bfc/ui` renders the same two values, but
| behind EnsureUiAuthority + EnsureUserIsAuthenticated, so it is not an
| anonymous surface and this file does not request it.) Issue #37: `icon`
| pointed at https://scalpels.app/icons/slate.svg and `product_url` at
| https://scalpels.app/products/slate, and both answered 404 — a broken image
| and a dead link on every deployed instance.
|
| The invariant is "each field publishes a URL that resolves AS THE KIND OF
| THING that field renders": an `<img src>` needs an image, an `<a href>` needs
| a page. So the verified URLs are split by role — one shared list would let a
| page URL pass as the icon, which still renders a broken image.
|
| A test that fetched these would make CI depend on scalpels.app being
| reachable, so they are pinned instead. Every list below was checked with curl
| on 2026-09-23, and adding a URL means checking it the same way: its status
| code AND its content type have to match the role of the list it joins.
|
*/

/**
 * scalpels.app URLs verified to answer HTTP 200 with an image content type.
 *
 * 2026-09-23: `.svg` -> 200 image/svg+xml, `.png` -> 200 image/png.
 *
 * @return array<int, string>
 */
function resolvingScalpelsImageUrls(): array
{
    return [
        'https://scalpels.app/img/products/transparent/slate.svg',
        'https://scalpels.app/img/products/transparent/slate.png',
    ];
}

/**
 * scalpels.app URLs verified to answer HTTP 200 with an HTML page.
 *
 * 2026-09-23: the home page -> 200 text/html. `/products/slate` joins this list
 * when that product page launches.
 *
 * @return array<int, string>
 */
function resolvingScalpelsPageUrls(): array
{
    return [
        'https://scalpels.app',
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
 * The URLs verified to resolve for each landing manifest field, keyed by its
 * configuration key. This mapping is the pin: a field may only carry a URL
 * verified to resolve as what that field renders.
 *
 * @return array<string, array<int, string>>
 */
function resolvingScalpelsUrlsByManifestField(): array
{
    return [
        'icon' => resolvingScalpelsImageUrls(),
        'product_url' => resolvingScalpelsPageUrls(),
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

/**
 * The value of one attribute on the element carrying a `data-testid`.
 */
function renderedAttributeForTestId(string $html, string $testId, string $attribute): string
{
    $pattern = '#<[a-z]+[^>]*\bdata-testid="'.preg_quote($testId, '#').'"[^>]*>#i';

    expect((bool) preg_match($pattern, $html, $element))->toBeTrue(
        "The landing page rendered no element with data-testid=\"{$testId}\".",
    );

    $attributePattern = '#\b'.preg_quote($attribute, '#').'="([^"]*)"#i';

    expect((bool) preg_match($attributePattern, $element[0], $value))->toBeTrue(
        "The landing page element [{$testId}] has no [{$attribute}] attribute.",
    );

    return $value[1];
}

it('publishes a URL verified to resolve as the right kind of resource for each manifest field', function (): void {
    foreach (landingManifestUrls() as $field => $url) {
        expect($url)->toBeIn(
            resolvingScalpelsUrlsByManifestField()[$field],
            "The landing manifest [{$field}] is [{$url}], which is not a scalpels.app URL anyone has verified resolves as what [{$field}] renders. The icon is an <img src> and needs an image; the product URL is an <a href> and needs a page.",
        );
    }
});

it('never publishes a scalpels.app URL that is known to 404', function (): void {
    foreach (landingManifestUrls() as $field => $url) {
        expect(in_array($url, missingScalpelsUrls(), true))->toBeFalse(
            "The landing manifest [{$field}] is [{$url}], which answers HTTP 404. Visitors get a broken image or a dead link.",
        );
    }
});

it('renders each manifest URL into its own attribute on the landing page', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toBeString();

    $rendered = [
        'icon' => renderedAttributeForTestId($html, 'landing-manifest-icon', 'src'),
        'product_url' => renderedAttributeForTestId($html, 'landing-manifest-product-link', 'href'),
    ];

    foreach ($rendered as $field => $url) {
        expect($url)->toBeIn(
            resolvingScalpelsUrlsByManifestField()[$field],
            "The landing page renders [{$url}] as the [{$field}], which is not a scalpels.app URL anyone has verified resolves as what [{$field}] renders.",
        );
    }
});

it('renders no unverified scalpels.app URL anywhere on the landing page', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toBeString();

    preg_match_all('#https://scalpels\.app[^\s"\'<>]*#', (string) $html, $matches);

    $rendered = array_values(array_unique($matches[0]));

    expect($rendered)->not->toBeEmpty('The landing page rendered no scalpels.app URL at all.');

    $verified = array_merge(resolvingScalpelsImageUrls(), resolvingScalpelsPageUrls());

    foreach ($rendered as $url) {
        expect($url)->toBeIn(
            $verified,
            "The landing page renders [{$url}], which is not a scalpels.app URL anyone has verified resolves.",
        );
    }
});
