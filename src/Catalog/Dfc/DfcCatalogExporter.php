<?php

declare(strict_types=1);

namespace App\Catalog\Dfc;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Delivery\Entity\DeliveryPoint;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Sale\Enum\SaleStatus;
use App\Sale\Repository\SaleRepository;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The shop's catalogue in the Data Food Consortium format (JSON-LD, ontology 1.16): an Enterprise
 * (the shop) maintaining a Catalog of CatalogItems, each referencing a SuppliedProduct and offered
 * through an Offer at the catalogue price; the open and scheduled sales as SaleSessions listing
 * their own Offers (sale price, remaining stock); the pick-up points as PhysicalPlaces with their
 * PickupOption. Home delivery zones are left out (no address to share).
 *
 * Only public information: what the shop page already shows. Never a customer, an order or a
 * quantity reserved by someone. Every node id is a fragment of the stable public URL of the
 * export, so the ids stay the same from one download to the next. Empty values are left out.
 */
final readonly class DfcCatalogExporter
{
    public function __construct(
        private ProductRepository $products,
        private DeliveryPointRepository $deliveryPoints,
        private SaleRepository $sales,
        private UrlGeneratorInterface $urlGenerator,
        private Packages $packages,
        private UrlHelper $urlHelper,
    ) {
    }

    /**
     * @return array{'@context': string, '@graph': list<array<string, mixed>>}
     */
    public function export(Shop $shop): array
    {
        $base = $this->urlGenerator->generate('shop_dfc_catalog', ['slug' => $shop->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $id = static fn (string $fragment): string => $base.'#'.$fragment;
        $exempt = $shop->isVatExempt();

        $graph = [];
        $catalogItems = [];
        $productIds = [];

        foreach ($this->products->findByShop($shop) as $product) {
            $key = (string) $product->getId();
            $productIds[] = $id('product-'.$key);
            $catalogItems[] = $id('catalog-item-'.$key);

            $graph[] = $this->suppliedProduct($product, $key, $id);
            $graph[] = [
                '@id' => $id('catalog-item-'.$key),
                '@type' => 'dfc-b:CatalogItem',
                'dfc-b:references' => $id('product-'.$key),
                'dfc-b:sku' => $key,
                'dfc-b:listedIn' => $id('catalog'),
                'dfc-b:offeredThrough' => $id('offer-'.$key),
            ];
            $graph[] = $this->offer($id('offer-'.$key), $id('catalog-item-'.$key), $product->getPriceCents(), $exempt ? 0 : $product->getVatRateBp(), null);
        }

        foreach ($this->sales->findByShopAndStatuses($shop, [SaleStatus::Open, SaleStatus::Scheduled]) as $sale) {
            array_push($graph, ...$this->saleSession($sale, $id, $exempt));
        }

        foreach ($this->deliveryPoints->findByShop($shop) as $point) {
            if (!$point->isHome()) {
                array_push($graph, ...$this->pickupPoint($point, $id));
            }
        }

        $address = $shop->getAddress();
        $head = [$this->compact([
            '@id' => $id('enterprise'),
            '@type' => 'dfc-b:Enterprise',
            'dfc-b:name' => $shop->getName(),
            'dfc-b:hasDescription' => $shop->getDescription(),
            'dfc-b:websitePage' => $this->urlGenerator->generate('shop_show', ['slug' => $shop->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            'dfc-b:logo' => $this->image($shop->getLogoPath()),
            'dfc-b:hasAddress' => null === $address || '' === $address ? null : $id('address'),
            'dfc-b:maintains' => $id('catalog'),
            'dfc-b:manages' => $catalogItems,
            'dfc-b:supplies' => $productIds,
        ])];
        if (null !== $address && '' !== $address) {
            $head[] = ['@id' => $id('address'), '@type' => 'dfc-b:Address', 'dfc-b:hasStreet' => $address, 'dfc-b:hasCountry' => DfcVocabulary::FRANCE];
        }
        $head[] = [
            '@id' => $id('catalog'),
            '@type' => 'dfc-b:Catalog',
            'dfc-b:maintainedBy' => $id('enterprise'),
            'dfc-b:lists' => $catalogItems,
        ];

        return ['@context' => DfcVocabulary::CONTEXT, '@graph' => [...$head, ...$graph]];
    }

    /**
     * @param \Closure(string): string $id
     *
     * @return array<string, mixed>
     */
    private function suppliedProduct(Product $product, string $key, \Closure $id): array
    {
        $certifications = [];
        $otherLabels = [];
        foreach ($product->getLabels() as $label) {
            $certification = DfcVocabulary::certification($label);
            if (null === $certification) {
                $otherLabels[] = $label;
            } elseif (!\in_array($certification, $certifications, true)) {
                $certifications[] = $certification;
            }
        }

        // Labels outside the DFC taxonomy and allergens stay readable in the description.
        $description = implode("\n\n", array_filter([
            $product->getDescription(),
            [] === $otherLabels ? null : 'Labels : '.implode(', ', $otherLabels),
            null === $product->getAllergens() || '' === $product->getAllergens() ? null : 'Allergènes : '.$product->getAllergens(),
        ], static fn (?string $part): bool => null !== $part && '' !== $part));

        return $this->compact([
            '@id' => $id('product-'.$key),
            '@type' => 'dfc-b:SuppliedProduct',
            'dfc-b:name' => $product->getName(),
            'dfc-b:description' => $description,
            'dfc-b:image' => $this->image($product->getPhotoPath()),
            'dfc-b:hasQuantity' => [
                '@type' => 'dfc-b:QuantitativeValue',
                'dfc-b:hasUnit' => DfcVocabulary::unit($product->getUnit()),
                'dfc-b:value' => '1',
            ],
            'dfc-b:hasCertification' => [] === $certifications ? null : $certifications,
            'dfc-b:referencedBy' => $id('catalog-item-'.$key),
        ]);
    }

    /**
     * @param \Closure(string): string $id
     *
     * @return list<array<string, mixed>>
     */
    private function saleSession(Sale $sale, \Closure $id, bool $exempt): array
    {
        $prefix = 'sale-'.$sale->getId();
        $nodes = [];
        $offers = [];

        foreach ($sale->getOffers() as $offer) {
            $product = $offer->getProduct();
            if ($product->isArchived()) {
                continue;
            }
            $offerId = $id($prefix.'-offer-'.$offer->getId());
            $offers[] = $offerId;
            $nodes[] = $this->offer($offerId, $id('catalog-item-'.$product->getId()), $offer->getPriceCents(), $exempt ? 0 : $offer->getVatRateBp(), $this->stock($offer));
        }

        array_unshift($nodes, [
            '@id' => $id($prefix),
            '@type' => 'dfc-b:SaleSession',
            'dfc-b:beginDate' => $sale->getOpensAt()->format(\DATE_ATOM),
            'dfc-b:endDate' => $sale->getDeadlineAt()->format(\DATE_ATOM),
            'dfc-b:lists' => $offers,
        ]);

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(string $offerId, string $catalogItemId, int $priceCents, int $vatRateBp, ?string $stock): array
    {
        return $this->compact([
            '@id' => $offerId,
            '@type' => 'dfc-b:Offer',
            'dfc-b:offeredItem' => $catalogItemId,
            'dfc-b:hasPrice' => [
                '@type' => 'dfc-b:Price',
                'dfc-b:value' => $this->hundredths($priceCents),
                'dfc-b:VATrate' => $this->hundredths($vatRateBp),
                'dfc-b:hasUnit' => DfcVocabulary::EURO,
            ],
            'dfc-b:stockLimitation' => $stock,
        ]);
    }

    /**
     * Quantity still available in the unit of the product ("2", "1.5"); null when unlimited.
     */
    private function stock(SaleOffer $offer): ?string
    {
        $remaining = $offer->remaining();

        return null === $remaining ? null : Quantity::decimal($remaining, '.');
    }

    /**
     * @param \Closure(string): string $id
     *
     * @return list<array<string, mixed>>
     */
    private function pickupPoint(DeliveryPoint $point, \Closure $id): array
    {
        $place = 'place-'.$point->getId();

        return [
            $this->compact([
                '@id' => $id($place),
                '@type' => 'dfc-b:PhysicalPlace',
                'dfc-b:name' => $point->getName(),
                'dfc-b:description' => $point->getInstructions(),
                'dfc-b:hasAddress' => $id($place.'-address'),
            ]),
            $this->compact([
                '@id' => $id($place.'-address'),
                '@type' => 'dfc-b:Address',
                'dfc-b:hasStreet' => $point->getAddress(),
                'dfc-b:hasPostalCode' => $point->getPostcode(),
                'dfc-b:hasCity' => $point->getCity(),
                'dfc-b:hasCountry' => DfcVocabulary::FRANCE,
                'dfc-b:latitude' => $point->getLatitude(),
                'dfc-b:longitude' => $point->getLongitude(),
            ]),
            [
                '@id' => $id('pickup-'.$point->getId()),
                '@type' => 'dfc-b:PickupOption',
                'dfc-b:pickedUpAt' => $id($place),
            ],
        ];
    }

    /**
     * Absolute URL of an uploaded image (relative to public/) or of a demo image (absolute path or URL).
     */
    private function image(?string $path): ?string
    {
        if (null === $path || '' === $path) {
            return null;
        }

        return $this->urlHelper->getAbsoluteUrl(str_starts_with($path, '/') || str_starts_with($path, 'http') ? $path : $this->packages->getUrl($path));
    }

    /**
     * Drops the null and empty values.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function compact(array $node): array
    {
        return array_filter($node, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * 450 → "4.5", 405 → "4.05", 1000 → "10" (cents or basis points): exact decimal, never a float.
     */
    private function hundredths(int $value): string
    {
        $fraction = rtrim(\sprintf('%02d', $value % 100), '0');

        return intdiv($value, 100).('' === $fraction ? '' : '.'.$fraction);
    }
}
