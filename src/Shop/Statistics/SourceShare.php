<?php

declare(strict_types=1);

namespace App\Shop\Statistics;

use App\Ordering\Enum\OrderSource;

/**
 * Where orders came from: an OrderSource, an order the producer entered, or unknown.
 */
final readonly class SourceShare
{
    public const string PRODUCER = 'producer';
    public const string UNKNOWN = 'unknown';

    public function __construct(
        public string $key,
        public Rate $share,
    ) {
    }

    public function label(): string
    {
        return match ($this->key) {
            self::PRODUCER => 'Saisies par toi',
            self::UNKNOWN => 'Adresse tapée ou lien sans provenance',
            default => OrderSource::tryFrom($this->key)?->label() ?? $this->key,
        };
    }
}
