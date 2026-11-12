<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Ordering\Enum\OrderSource;
use App\Sale\Entity\Sale;
use App\Shared\Service\FrenchFormatter;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Public link of a sale and its prefilled WhatsApp message (SPEC §1.5: a share link, nothing automatic).
 */
final readonly class SaleShareLink
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private FrenchFormatter $formatter,
    ) {
    }

    /**
     * Absolute URL of the public sale page: `/{slug}/vente/{code}`.
     */
    public function publicUrl(Sale $sale): string
    {
        $slug = $sale->getShop()->getSlug();

        try {
            return $this->urlGenerator->generate('sale_show', ['slug' => $slug, 'code' => $sale->getCode()], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (ExceptionInterface) {
            // The public page is built in another feature set: fall back to its frozen path (SPEC §5).
            $context = $this->urlGenerator->getContext();
            $scheme = $context->getScheme();
            $port = 'https' === $scheme ? $context->getHttpsPort() : $context->getHttpPort();
            $defaultPort = 'https' === $scheme ? 443 : 80;

            return \sprintf(
                '%s://%s%s%s/%s/vente/%s',
                $scheme,
                $context->getHost(),
                $port === $defaultPort ? '' : ':'.$port,
                $context->getBaseUrl(),
                rawurlencode($slug),
                rawurlencode($sale->getCode()),
            );
        }
    }

    public function message(Sale $sale): string
    {
        $products = [];
        foreach ($sale->getOffers() as $offer) {
            $products[] = $offer->getProduct()->getName();
        }

        $lines = [
            \sprintf('%s : %s', $sale->getShop()->getName(), $sale->getTitle()),
            \sprintf('Livraison %s', $this->formatter->dateLong($sale->getDeliveryDate())),
        ];
        if ([] !== $products) {
            $lines[] = \sprintf('Au menu : %s', implode(', ', $products));
        }
        $lines[] = \sprintf('Commande avant %s', $this->formatter->dateTimeLong($sale->getDeadlineAt()));
        // Marked as shared by the producer: kept until the order (OrderSource, no cookie).
        $lines[] = $this->publicUrl($sale).'?via='.OrderSource::VIA_SHARED_LINK;

        return implode("\n", $lines);
    }

    public function whatsAppUrl(Sale $sale): string
    {
        return 'https://wa.me/?text='.rawurlencode($this->message($sale));
    }
}
