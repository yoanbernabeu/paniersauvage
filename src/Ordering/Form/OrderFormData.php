<?php

declare(strict_types=1);

namespace App\Ordering\Form;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Model\CustomerIdentity;
use App\Ordering\Model\OrderRequest;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;

/**
 * Data behind the order form (customer page, customer edit, producer add/edit).
 */
final class OrderFormData
{
    public ?SaleDelivery $delivery = null;

    public ?string $deliveryAddress = null;

    /** @var array<int, int|null> sale offer id => quantity, in thousandths of the unit (App\Shared\Service\Quantity) */
    public array $quantities = [];

    public ?string $note = null;

    /** @var array<int, int|null> order line id => actual weight in thousandths (producer edit, products sold by weight) */
    public array $actualQuantities = [];

    public ?string $email = null;

    public ?string $firstName = null;

    public ?string $lastName = null;

    public ?string $phone = null;

    /** "I accept the terms of use" (`terms` option of the form). */
    public bool $acceptTerms = false;

    /** "I certify I am 18 or over" (`age_check` option), required when an alcoholic drink is ordered. */
    public bool $adultCertified = false;

    /**
     * A blank order; the delivery is preselected when the sale has only one.
     */
    public static function forSale(Sale $sale, ?Customer $customer = null): self
    {
        $data = new self();

        if (1 === $sale->getDeliveries()->count()) {
            $data->delivery = $sale->getDeliveries()->first() ?: null;
        }

        if ($customer instanceof Customer) {
            $data->email = $customer->getEmail();
            $data->firstName = $customer->getFirstName();
            $data->lastName = $customer->getLastName();
            $data->phone = $customer->getPhone();
        }

        return $data;
    }

    public static function fromOrder(Order $order): self
    {
        $data = new self();
        $data->delivery = $order->getSaleDelivery();
        $data->deliveryAddress = $order->getDeliveryAddress();
        $data->note = $order->getNote();

        foreach ($order->getLines() as $line) {
            $offerId = $line->getSaleOffer()->getId();
            if (null !== $offerId) {
                $data->quantities[$offerId] = $line->getQuantity();
            }
            if ($line->isWeighed() && null !== $line->getId()) {
                $data->actualQuantities[$line->getId()] = $line->getActualQuantity();
            }
        }

        return $data;
    }

    /**
     * Actual weights still relevant for the order: lines removed by the update are left out.
     *
     * @return array<int, int|null> order line id => actual weight in thousandths
     */
    public function weightsFor(Order $order): array
    {
        $weights = [];
        foreach ($order->getLines() as $line) {
            $lineId = $line->getId();
            if (null !== $lineId && $line->isWeighed() && \array_key_exists($lineId, $this->actualQuantities)) {
                $weights[$lineId] = $this->actualQuantities[$lineId];
            }
        }

        return $weights;
    }

    /**
     * "Reprendre ma commande précédente": same products as a previous order at this producer,
     * limited to what this sale offers to customers (its leftovers during a last-minute sale) and
     * still has in stock.
     */
    public function prefillFrom(Order $previous, Sale $sale): void
    {
        foreach ($previous->getLines() as $line) {
            $offer = $sale->getOfferFor($line->getSaleOffer()->getProduct());
            $offerId = $offer?->getId();
            if (!$offer instanceof SaleOffer || null === $offerId) {
                continue;
            }
            if (!\in_array($offer, $sale->getOrderableOffers(), true)) {
                continue;
            }

            $product = $offer->getProduct();
            $quantity = min($line->getQuantity(), $offer->remaining() ?? $line->getQuantity());
            $quantity -= $quantity % $product->getQuantityStep();
            if ($product->acceptsOrderQuantity($quantity)) {
                $this->quantities[$offerId] = $quantity;
            }
        }
    }

    /**
     * @return array<int, int> sale offer id => quantity, without the empty ones
     */
    public function positiveQuantities(): array
    {
        $quantities = [];
        foreach ($this->quantities as $offerId => $quantity) {
            if ($quantity > 0) {
                $quantities[$offerId] = $quantity;
            }
        }

        return $quantities;
    }

    /**
     * Named so that the form never reads it as the `email` property (PropertyAccessor tries hasEmail()).
     */
    public function isEmailGiven(): bool
    {
        return '' !== trim($this->email ?? '');
    }

    public function hasItems(): bool
    {
        return [] !== $this->positiveQuantities();
    }

    /**
     * What the basket costs, before any weighing: lines of the order being edited keep their frozen price.
     */
    public function estimatedTotalCents(Sale $sale, ?Order $order = null): int
    {
        $total = 0;
        foreach ($this->basket($sale) as [$offer, $quantity]) {
            $total += Quantity::priceCents($quantity, $order?->getLineFor($offer)?->getUnitPriceCents() ?? $offer->getPriceCents());
        }

        return $total;
    }

    /**
     * Items in the basket: pieces and bunches, one per product sold by weight.
     */
    public function itemCount(Sale $sale): int
    {
        $count = 0;
        foreach ($this->basket($sale) as [$offer, $quantity]) {
            $count += $offer->getProduct()->getUnit()->isWeighed() ? 1 : intdiv($quantity, Quantity::UNIT);
        }

        return $count;
    }

    /**
     * Is at least one product sold by weight in the basket (its amount is then an estimate)?
     */
    public function hasWeighedItems(Sale $sale): bool
    {
        foreach ($this->basket($sale) as [$offer]) {
            if ($offer->getProduct()->getUnit()->isWeighed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{SaleOffer, int}> the offers of the sale in the basket, with their quantity
     */
    private function basket(Sale $sale): array
    {
        $basket = [];
        foreach ($sale->getOffers() as $offer) {
            $quantity = $this->positiveQuantities()[$offer->getId()] ?? null;
            if (null !== $quantity) {
                $basket[] = [$offer, $quantity];
            }
        }

        return $basket;
    }

    /**
     * Does the basket hold an alcoholic drink of this sale?
     */
    public function ordersAlcohol(Sale $sale): bool
    {
        $quantities = $this->positiveQuantities();

        foreach ($sale->getOffers() as $offer) {
            if (isset($quantities[(int) $offer->getId()]) && $offer->getProduct()->isAlcoholic()) {
                return true;
            }
        }

        return false;
    }

    public function toRequest(): OrderRequest
    {
        return new OrderRequest(
            $this->delivery ?? throw new \LogicException('The form must be valid before building the request.'),
            $this->positiveQuantities(),
            $this->note,
            $this->deliveryAddress,
            null === $this->firstName && null === $this->lastName ? null : new CustomerIdentity($this->firstName, $this->lastName, $this->phone),
        );
    }

    /**
     * After a stock refusal: lowers the quantities to what can still be ordered and returns the
     * messages to show.
     *
     * @return list<string>
     */
    public function applyShortages(InsufficientStockException $exception, ?Order $order = null): array
    {
        $messages = [];

        foreach ($exception->getShortages() as $shortage) {
            $own = 0;
            foreach ($order?->getLines() ?? [] as $line) {
                if ($line->getSaleOffer()->getId() === $shortage->offerId) {
                    $own = $line->getQuantity();
                }
            }

            $available = $shortage->orderable($own + $shortage->remaining);
            $this->quantities[$shortage->offerId] = $available;

            $messages[] = match (true) {
                0 === $available => \sprintf('« %s » vient de partir : il n\'en reste plus.', $shortage->label),
                $shortage->unit->isWeighed() => \sprintf('Il ne reste plus que %s kg de « %s ».', Quantity::decimal($available), $shortage->label),
                Quantity::UNIT === $available => \sprintf('Il ne reste plus qu\'un seul « %s ».', $shortage->label),
                default => \sprintf('Il ne reste plus que %s « %s ».', Quantity::decimal($available), $shortage->label),
            };
        }

        return $messages;
    }
}
