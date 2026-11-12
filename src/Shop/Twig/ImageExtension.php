<?php

declare(strict_types=1);

namespace App\Shop\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Attribute\AsTwigFilter;

final readonly class ImageExtension
{
    public function __construct(
        private Packages $packages,
    ) {
    }

    /**
     * {{ shop.logoPath|image_url }} — URL of an uploaded image (shop logo, product photo).
     * Stored paths are relative to public/ (ImageStorage); absolute paths and URLs (demo data) are kept.
     */
    #[AsTwigFilter('image_url')]
    public function imageUrl(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }

        if (str_starts_with($path, '/') || 1 === preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return $this->packages->getUrl($path);
    }
}
