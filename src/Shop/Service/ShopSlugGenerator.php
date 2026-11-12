<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Shop\Entity\Shop;
use App\Shop\Repository\ShopRepository;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Builds the public address of a shop (`/{slug}`) from its name: readable, unique and
 * never one of the reserved slugs that would shadow an application route.
 */
final readonly class ShopSlugGenerator
{
    private const int MIN_LENGTH = 3;

    /** Leaves room for a numeric suffix within the 80 characters of the column. */
    private const int MAX_BASE_LENGTH = 60;

    private const string FALLBACK = 'producteur';

    public function __construct(
        private SluggerInterface $slugger,
        private ShopRepository $shops,
    ) {
    }

    public function generate(string $name): string
    {
        $base = mb_strtolower($this->slugger->slug($name, '-', 'fr')->toString());
        $base = trim(substr($base, 0, self::MAX_BASE_LENGTH), '-');

        if ('' === $base) {
            $base = self::FALLBACK;
        } elseif (\strlen($base) < self::MIN_LENGTH || Shop::isReservedSlug($base)) {
            $base = 'chez-'.$base;
        }

        $slug = $base;
        for ($suffix = 2; Shop::isReservedSlug($slug) || $this->shops->slugExists($slug); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
