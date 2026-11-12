<?php

declare(strict_types=1);

namespace App\Sale\Service;

use App\Catalog\Repository\ProductRepository;
use App\Delivery\Repository\DeliveryPointRepository;
use App\Ordering\Service\SaleOrders;
use App\Sale\Entity\Sale;
use App\Sale\Form\Model\SaleData;
use App\Sale\Form\Model\SaleDeliveryData;
use App\Sale\Form\Model\SaleOfferData;
use App\Shop\Entity\Shop;
use Psr\Clock\ClockInterface;

/**
 * Builds the data of the sale form: defaults for a new sale, current values for an existing one.
 */
final readonly class SaleDataFactory
{
    private const int DEFAULT_DELIVERY_IN_DAYS = 7;
    private const int DEFAULT_DEADLINE_DAYS_BEFORE = 3;

    public function __construct(
        private ProductRepository $products,
        private DeliveryPointRepository $deliveryPoints,
        private SaleOrders $saleOrders,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A one-off sale delivered in a week, prefilled with the permanent range and the seasonal
     * products available on that day.
     */
    public function forNewSale(Shop $shop): SaleData
    {
        $now = $this->clock->now();
        $deliveryDate = $now->modify(\sprintf('+%d days', self::DEFAULT_DELIVERY_IN_DAYS))->setTime(0, 0);

        $data = new SaleData();
        $data->now = $now;
        $data->deliveryDate = $deliveryDate;
        $data->opensAt = $now->setTime((int) $now->format('H'), (int) $now->format('i'));
        $data->deadlineAt = $deliveryDate->modify(\sprintf('-%d days', self::DEFAULT_DEADLINE_DAYS_BEFORE))->setTime(20, 0);
        $data->allowCustomerEdit = $shop->isAllowCustomerEdit();
        $data->editCutoffHours = $shop->getDefaultEditCutoffHours();

        $points = $this->deliveryPoints->findByShop($shop);
        foreach ($points as $point) {
            $row = new SaleDeliveryData($point);
            $row->selected = 1 === \count($points);
            $row->startTime = new \DateTimeImmutable('1970-01-01 18:00:00');
            $row->endTime = new \DateTimeImmutable('1970-01-01 19:00:00');
            $data->deliveries[] = $row;
        }

        foreach ($this->products->findByShop($shop) as $product) {
            $row = new SaleOfferData($product);
            $row->selected = $product->isAvailableOn($deliveryDate);
            $row->priceCents = $product->getPriceCents();
            $row->maxQty = $product->getMaxQtyDefault();
            $data->offers[] = $row;
        }

        return $data;
    }

    /**
     * Current values of the sale, followed by the other delivery points and products of the shop (unticked).
     */
    public function fromSale(Sale $sale): SaleData
    {
        $shop = $sale->getShop();
        $deadlineAt = $sale->getDeadlineAt();
        $editCutoffAt = $sale->getEditCutoffAt();

        $data = new SaleData();
        $data->title = $sale->getTitle();
        $data->deliveryDate = $sale->getDeliveryDate();
        $data->opensAt = $sale->getOpensAt();
        $data->deadlineAt = $deadlineAt;
        $data->allowCustomerEdit = $sale->isAllowCustomerEdit();
        $data->editCutoffHours = $editCutoffAt instanceof \DateTimeImmutable
            ? max(0, intdiv($deadlineAt->getTimestamp() - $editCutoffAt->getTimestamp(), 3600))
            : null;

        // Customers expect their order on that day: with orders, the delivery date no longer changes.
        $data->deliveryDateLocked = [] !== $this->saleOrders->countBySale([$sale]);

        $deliveryCounts = $this->saleOrders->countByDelivery($sale);
        $usedPoints = [];
        foreach ($sale->getDeliveries() as $delivery) {
            $counts = $deliveryCounts[(int) $delivery->getId()] ?? ['all' => 0, 'active' => 0];
            $row = new SaleDeliveryData($delivery->getDeliveryPoint(), $delivery, $counts['all'] > 0, $counts['active'] > 0);
            $row->selected = true;
            $row->startTime = $delivery->getStartsAt();
            $row->endTime = $delivery->getEndsAt();
            $data->deliveries[] = $row;
            $usedPoints[] = $delivery->getDeliveryPoint();
        }
        foreach ($this->deliveryPoints->findByShop($shop) as $point) {
            if (\in_array($point, $usedPoints, true)) {
                continue;
            }

            $row = new SaleDeliveryData($point);
            $row->startTime = new \DateTimeImmutable('1970-01-01 18:00:00');
            $row->endTime = new \DateTimeImmutable('1970-01-01 19:00:00');
            $data->deliveries[] = $row;
        }

        $orderedOfferIds = $this->saleOrders->orderedOfferIds($sale);
        $usedProducts = [];
        foreach ($sale->getOffers() as $offer) {
            $row = new SaleOfferData($offer->getProduct(), $offer, \in_array($offer->getId(), $orderedOfferIds, true));
            $row->selected = true;
            $row->priceCents = $offer->getPriceCents();
            $row->maxQty = $offer->getMaxQty();
            $data->offers[] = $row;
            $usedProducts[] = $offer->getProduct();
        }
        foreach ($this->products->findByShop($shop) as $product) {
            if (\in_array($product, $usedProducts, true)) {
                continue;
            }

            $row = new SaleOfferData($product);
            $row->priceCents = $product->getPriceCents();
            $row->maxQty = $product->getMaxQtyDefault();
            $data->offers[] = $row;
        }

        return $data;
    }
}
