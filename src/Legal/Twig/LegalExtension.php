<?php

declare(strict_types=1);

namespace App\Legal\Twig;

use App\Legal\Entity\ShopVerification;
use App\Legal\LegalDocuments;
use App\Legal\Repository\ShopVerificationRepository;
use App\Legal\Service\PublisherIdentity;
use App\Shop\Entity\Shop;
use Twig\Attribute\AsTwigFunction;

final readonly class LegalExtension
{
    public function __construct(
        private PublisherIdentity $publisher,
        private ShopVerificationRepository $verifications,
    ) {
    }

    /** {{ legal_publisher().name }} — publisher and host of the platform (LEGAL_* variables). */
    #[AsTwigFunction('legal_publisher')]
    public function publisher(): PublisherIdentity
    {
        return $this->publisher;
    }

    /** {{ terms_version() }} — current version of the terms of use. */
    #[AsTwigFunction('terms_version')]
    public function termsVersion(): string
    {
        return LegalDocuments::TERMS_VERSION;
    }

    /** {% set verification = shop_verification(shop) %} — the check of the shop's SIRET, or null. */
    #[AsTwigFunction('shop_verification')]
    public function shopVerification(Shop $shop): ?ShopVerification
    {
        return $this->verifications->findOneByShop($shop);
    }

    /** {% if shop_verified(shop) %} — SIRET checked against the public company register. */
    #[AsTwigFunction('shop_verified')]
    public function shopVerified(Shop $shop): bool
    {
        return $this->verifications->isVerified($shop);
    }
}
