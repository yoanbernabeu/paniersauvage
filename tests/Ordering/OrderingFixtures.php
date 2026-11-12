<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builders shared by the ordering tests: a producer with a sale, offers, deliveries, and real
 * orders placed through OrderManager (so stock counters are right).
 */
trait OrderingFixtures
{
    private function createProducer(): User
    {
        return UserFactory::createOne();
    }

    private function shopOf(User $producer): Shop
    {
        return $producer->getShop() ?? throw new \LogicException('The producer has no shop.');
    }

    /**
     * An open sale with one meeting-point delivery.
     *
     * @param array<string, mixed> $attributes
     */
    private function createSale(Shop $shop, array $attributes = []): Sale
    {
        $sale = SaleFactory::createOne(['shop' => $shop, ...$attributes]);
        SaleDeliveryFactory::createOne(['sale' => $sale]);

        return $sale;
    }

    private function deliveryOf(Sale $sale): SaleDelivery
    {
        return $sale->getDeliveries()->first() ?: throw new \LogicException('The sale has no delivery.');
    }

    private function addHomeDelivery(Sale $sale): SaleDelivery
    {
        return SaleDeliveryFactory::createOne([
            'sale' => $sale,
            'deliveryPoint' => DeliveryPointFactory::new(['shop' => $sale->getShop()])->home(),
            'position' => 5,
        ]);
    }

    /**
     * @param int|null             $maxQty  limit in whole units (pieces), null = unlimited
     * @param array<string, mixed> $product extra product attributes
     */
    private function createOffer(Sale $sale, string $name, int $priceCents, ?int $maxQty = null, array $product = []): SaleOffer
    {
        return SaleOfferFactory::createOne([
            'sale' => $sale,
            'product' => ProductFactory::new(['shop' => $sale->getShop(), 'name' => $name, 'priceCents' => $priceCents, ...$product]),
            'maxQty' => null === $maxQty ? null : Quantity::units($maxQty),
        ]);
    }

    private function createCustomer(string $email = 'lea@example.test', ?string $firstName = 'Léa', ?string $lastName = 'Martin'): Customer
    {
        return CustomerFactory::createOne(['email' => $email, 'firstName' => $firstName, 'lastName' => $lastName]);
    }

    /**
     * Places a real order (stock reserved) as the producer, so it also works on closed sales.
     *
     * @param array<int, int> $quantities sale offer id => quantity in whole units (pieces)
     */
    private function placeOrder(Sale $sale, Customer $customer, array $quantities, ?SaleDelivery $delivery = null, ?string $address = null): Order
    {
        return self::getContainer()->get(OrderManager::class)->placeOrder(
            $sale,
            $customer,
            new OrderRequest($delivery ?? $this->deliveryOf($sale), array_map(Quantity::units(...), $quantities), null, $address),
            Actor::Producer,
        );
    }

    private static function idOf(SaleOffer|Order|Sale $entity): int
    {
        return $entity->getId() ?? throw new \LogicException('The entity is not persisted.');
    }

    /**
     * Reads the entity again from the database, bypassing the identity map.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, int $id): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find($class, $id) ?? throw new \LogicException(\sprintf('%s #%d not found.', $class, $id));
    }

    /**
     * Reserved stock read from the database, in whole units (pieces).
     */
    private function reservedQty(SaleOffer $offer): int
    {
        $value = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT reserved_qty FROM sale_offer WHERE id = ?', [self::idOf($offer)]);

        return is_numeric($value) ? intdiv((int) $value, Quantity::UNIT) : throw new \LogicException('Offer not found.');
    }
}
