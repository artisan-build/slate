<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\Audio\AudioBitrate;
use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\QualityTier;
use App\Slate\Recipe\Video\VideoColor;
use App\Slate\Recipe\Video\VideoFit;
use App\Slate\Recipe\Video\VideoTransition;
use App\Slate\Storage\ObjectKey;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class RunRecipe implements Arrayable, JsonSerializable
{
    /** @var non-empty-list<RunRecipeOperation> */
    public array $operations;

    /** @param array<array-key, mixed> $operations */
    public function __construct(array $operations)
    {
        if ($operations === [] || ! array_is_list($operations)) {
            throw new InvalidArgumentException('Run recipe operations must be a non-empty list.');
        }

        foreach ($operations as $operation) {
            if (! $operation instanceof RunRecipeOperation) {
                throw new InvalidArgumentException('Run recipes accept only typed operations.');
            }
        }

        $this->operations = $operations;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $runPrefix): self
    {
        $operations = $data['operations'] ?? null;

        if (array_keys($data) !== ['operations'] || ! is_array($operations) || ! array_is_list($operations)) {
            throw new InvalidArgumentException('Run recipe payload must contain an operation list.');
        }

        return new self(array_map(
            static function (mixed $operation) use ($runPrefix): RunRecipeOperation {
                if (! is_array($operation)) {
                    throw new InvalidArgumentException('Run recipe operation payload must be an object.');
                }

                return match ($operation['operation'] ?? null) {
                    'video.still_clip' => RunStillClip::fromArray($operation, $runPrefix),
                    'video.clip' => RunVideoClip::fromArray($operation, $runPrefix),
                    default => throw new InvalidArgumentException('Run recipe contains an unsupported operation.'),
                };
            },
            $operations,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(string): ObjectKey  $resolveSource
     */
    public static function fromWireArray(array $data, callable $resolveSource): self
    {
        if (array_keys($data) === ['delivery'] && is_array($data['delivery'])) {
            return self::deliveryFromWire($data['delivery'], $resolveSource);
        }

        if (array_keys($data) === ['highlights'] && is_array($data['highlights'])) {
            return self::highlightsFromWire($data['highlights'], $resolveSource);
        }

        throw new InvalidArgumentException('Run recipe must contain exactly one delivery or highlights object.');
    }

    /** @throws JsonException */
    public static function fromJson(string $json, string $runPrefix): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Stored run recipe must decode to an object.');
        }

        return self::fromArray($data, $runPrefix);
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{operations: non-empty-list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'operations' => array_map(
                static fn (RunRecipeOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
        ];
    }

    /** @return array{operations: non-empty-list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param array<string, mixed> $data @param callable(string): ObjectKey $resolveSource */
    private static function deliveryFromWire(array $data, callable $resolveSource): self
    {
        self::assertExactKeys($data, ['pages', 'transitions', 'quality_tier', 'width', 'height'], 'Delivery');
        $pages = $data['pages'] ?? null;
        $qualityTier = QualityTier::tryFrom(is_string($data['quality_tier'] ?? null) ? $data['quality_tier'] : '');

        if (! is_array($pages) || ! array_is_list($pages) || count($pages) < 2
            || ! is_int($data['width']) || ! is_int($data['height']) || $qualityTier === null) {
            throw new InvalidArgumentException('Delivery payload does not match its complete typed schema.');
        }

        self::assertTransition($data['transitions'] ?? null);

        return new self(array_map(
            static function (mixed $page) use ($resolveSource, $data, $qualityTier): RunStillClip {
                if (! is_array($page)) {
                    throw new InvalidArgumentException('Delivery page payload must be an object.');
                }

                $allowedKeys = ['image', 'audio', 'duration'];

                if (array_key_exists('captions', $page)) {
                    $allowedKeys[] = 'captions';
                }

                self::assertExactKeys($page, $allowedKeys, 'Delivery page');
                $duration = $page['duration'] ?? null;

                if (! is_string($page['image']) || ! is_string($page['audio'])
                    || (! is_int($duration) && ! is_float($duration))
                    || ! AudioDuration::isPositiveMicrosecondDuration((float) $duration)) {
                    throw new InvalidArgumentException('Delivery page payload does not match its complete typed schema.');
                }

                return new RunStillClip(
                    self::resolveSourceId($page['image'], $resolveSource),
                    self::resolveSourceId($page['audio'], $resolveSource),
                    $data['width'],
                    $data['height'],
                    24,
                    (int) round((float) $duration * 1_000_000),
                    VideoFit::ContainPad,
                    VideoColor::Black,
                    1,
                    $qualityTier,
                    AudioBitrate::Kbps192,
                    self::captionsFromWire($page['captions'] ?? null, $resolveSource),
                );
            },
            $pages,
        ));
    }

    /** @param array<string, mixed> $data @param callable(string): ObjectKey $resolveSource */
    private static function highlightsFromWire(array $data, callable $resolveSource): self
    {
        self::assertExactKeys($data, ['source', 'clips', 'transitions', 'quality_tier'], 'Highlights');
        $clips = $data['clips'] ?? null;
        $qualityTier = QualityTier::tryFrom(is_string($data['quality_tier'] ?? null) ? $data['quality_tier'] : '');

        if (! is_string($data['source']) || ! is_array($clips) || ! array_is_list($clips)
            || count($clips) < 2 || $qualityTier === null) {
            throw new InvalidArgumentException('Highlights payload does not match its complete typed schema.');
        }

        self::assertTransition($data['transitions'] ?? null);
        $source = self::resolveSourceId($data['source'], $resolveSource);

        return new self(array_map(static function (mixed $clip) use ($source, $qualityTier): RunVideoClip {
            if (! is_array($clip)) {
                throw new InvalidArgumentException('Highlight clip payload must be an object.');
            }

            self::assertExactKeys($clip, ['start', 'end'], 'Highlight clip');

            if ((! is_int($clip['start']) && ! is_float($clip['start']))
                || (! is_int($clip['end']) && ! is_float($clip['end']))) {
                throw new InvalidArgumentException('Highlight clip payload does not match its complete typed schema.');
            }

            return new RunVideoClip($source, (float) $clip['start'], (float) $clip['end'], $qualityTier);
        }, $clips));
    }

    /** @param callable(string): ObjectKey $resolveSource */
    private static function captionsFromWire(mixed $data, callable $resolveSource): ?RunTimedCaptions
    {
        if ($data === null) {
            return null;
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Delivery captions payload must be an object.');
        }

        self::assertExactKeys($data, ['font', 'words'], 'Delivery captions');
        $words = $data['words'] ?? null;

        if (! is_string($data['font']) || ! is_array($words) || ! array_is_list($words)) {
            throw new InvalidArgumentException('Delivery captions payload does not match its complete typed schema.');
        }

        return new RunTimedCaptions(
            self::resolveSourceId($data['font'], $resolveSource),
            array_map(static function (mixed $word): RunTimedWord {
                if (! is_array($word)) {
                    throw new InvalidArgumentException('Caption word payload must be an object.');
                }

                return RunTimedWord::fromArray($word);
            }, $words),
        );
    }

    private static function assertTransition(mixed $data): void
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('Transitions payload must be an object.');
        }

        self::assertExactKeys($data, ['type', 'duration'], 'Transitions');

        if (($data['type'] ?? null) !== VideoTransition::Fade->value
            || ((! is_int($data['duration']) && ! is_float($data['duration'])) || (float) $data['duration'] !== 1.0)) {
            throw new InvalidArgumentException('Transitions must use the audited one-second fade contract.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $expectedKeys
     */
    private static function assertExactKeys(array $data, array $expectedKeys, string $label): void
    {
        $actualKeys = array_keys($data);
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException("{$label} payload contains missing or unsupported fields.");
        }
    }

    /** @param callable(string): ObjectKey $resolveSource */
    private static function resolveSourceId(string $sourceId, callable $resolveSource): ObjectKey
    {
        if (! Str::isUuid($sourceId)) {
            throw new InvalidArgumentException('Recipe source references must be UUID source ids.');
        }

        return $resolveSource($sourceId);
    }
}
