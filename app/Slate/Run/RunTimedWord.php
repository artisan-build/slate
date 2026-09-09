<?php

declare(strict_types=1);

namespace App\Slate\Run;

use App\Slate\Recipe\Audio\AudioDuration;
use App\Slate\Recipe\Caption\TimedWord;
use InvalidArgumentException;

final readonly class RunTimedWord
{
    public function __construct(
        public string $text,
        public int $x,
        public int $y,
        public float $start,
        public float $end,
    ) {
        if (! AudioDuration::isNonNegativeMicrosecondDuration($start)
            || ! AudioDuration::isPositiveMicrosecondDuration($end)
            || $end <= $start) {
            throw new InvalidArgumentException('A timed word requires an increasing non-negative time range.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['end', 'start', 'text', 'x', 'y']
            || ! is_string($data['text'])
            || ! is_int($data['x'])
            || ! is_int($data['y'])
            || (! is_int($data['start']) && ! is_float($data['start']))
            || (! is_int($data['end']) && ! is_float($data['end']))) {
            throw new InvalidArgumentException('Timed word payload does not match its complete typed schema.');
        }

        return new self($data['text'], $data['x'], $data['y'], (float) $data['start'], (float) $data['end']);
    }

    public function local(float $dimDurationSeconds): TimedWord
    {
        $entryDuration = min(0.1, ($this->end - $this->start - $dimDurationSeconds) / 3);

        return TimedWord::fromValues(
            $this->text,
            $this->x,
            $this->y,
            $this->start,
            $entryDuration,
            $this->end - $entryDuration - $dimDurationSeconds,
            $this->end - $entryDuration,
            $entryDuration,
        );
    }

    /** @return array{text: string, x: int, y: int, start: float, end: float} */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'x' => $this->x,
            'y' => $this->y,
            'start' => $this->start,
            'end' => $this->end,
        ];
    }
}
