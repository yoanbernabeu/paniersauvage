<?php

declare(strict_types=1);

namespace App\Shop\Search;

use App\Shop\Enum\ShopCategory;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Optional filters of the producer directory, read from the query string:
 * `?categorie=bread&bio=1&cheques-alimentaires=1&monnaie-locale=1`. They narrow the list down,
 * never reorder it: results stay ranked by distance only.
 */
final readonly class SearchFilters
{
    public const string CATEGORY = 'categorie';
    public const string ORGANIC = 'bio';
    public const string FOOD_VOUCHERS = 'cheques-alimentaires';
    public const string LOCAL_CURRENCY = 'monnaie-locale';

    public function __construct(
        public ?ShopCategory $category = null,
        /** At least one product carries an organic label ("Bio", "AB", "Agriculture biologique"). */
        public bool $organic = false,
        public bool $foodVouchers = false,
        public bool $localCurrency = false,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        $values = $query->all();
        $category = $values[self::CATEGORY] ?? null;

        return new self(
            \is_string($category) ? ShopCategory::tryFrom($category) : null,
            self::flag($values, self::ORGANIC),
            self::flag($values, self::FOOD_VOUCHERS),
            self::flag($values, self::LOCAL_CURRENCY),
        );
    }

    public function isEmpty(): bool
    {
        return [] === $this->toQuery();
    }

    /**
     * The filters as query parameters, to keep them in the links of the page.
     *
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        return array_filter([
            self::CATEGORY => $this->category->value ?? '',
            self::ORGANIC => (int) $this->organic,
            self::FOOD_VOUCHERS => (int) $this->foodVouchers,
            self::LOCAL_CURRENCY => (int) $this->localCurrency,
        ], static fn (string|int $value): bool => '' !== $value && 0 !== $value);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function flag(array $values, string $key): bool
    {
        return '1' === ($values[$key] ?? null);
    }
}
