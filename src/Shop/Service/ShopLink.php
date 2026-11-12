<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Ordering\Enum\OrderSource;
use App\Shop\Entity\Shop;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public address of a shop (`/{slug}`), the same address marked as shared by the producer
 * (`?via=lien`, kept until the order: OrderSource) and its ready-to-share WhatsApp link.
 */
final readonly class ShopLink
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Absolute URL of the public shop page.
     */
    public function publicUrl(Shop $shop): string
    {
        try {
            return $this->urlGenerator->generate('shop_show', ['slug' => $shop->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (ExceptionInterface) {
            // The public page belongs to another feature set: fall back on its frozen path (SPEC §5).
            $context = $this->urlGenerator->getContext();
            $port = match ($context->getScheme()) {
                'http' => 80 === $context->getHttpPort() ? '' : ':'.$context->getHttpPort(),
                default => 443 === $context->getHttpsPort() ? '' : ':'.$context->getHttpsPort(),
            };

            return \sprintf('%s://%s%s%s/%s', $context->getScheme(), $context->getHost(), $port, $context->getBaseUrl(), $shop->getSlug());
        }
    }

    /**
     * The public URL as the producer shares it: orders placed from it count as "shared link".
     */
    public function shareUrl(Shop $shop): string
    {
        return $this->publicUrl($shop).'?via='.OrderSource::VIA_SHARED_LINK;
    }

    /**
     * Opens WhatsApp with a prefilled message; nothing is sent automatically (SPEC §1.5).
     */
    public function whatsAppUrl(Shop $shop): string
    {
        $message = \sprintf('Bonjour ! Tu peux maintenant commander en ligne chez %s : %s', $shop->getName(), $this->shareUrl($shop));

        return 'https://wa.me/?text='.rawurlencode($message);
    }
}
