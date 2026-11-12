<?php

declare(strict_types=1);

namespace App\Tests\Recurring;

use App\Catalog\Entity\Product;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Notification\Service\NotificationSender;
use App\Recurring\Entity\RecurringOccurrence;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Enum\RecurringMode;
use App\Recurring\Service\RecurringOrderGenerator;
use App\Recurring\Service\RecurringOrderManager;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Repository\SaleRepository;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Sale\SaleScenario;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mime\Email;

/**
 * Shared set-up of the recurring order tests: a producer in trial, a weekly recurrence with one
 * pick-up point, a plan on it, subscribers and open sales of the recurrence.
 */
trait RecurringScenario
{
    use SaleScenario;

    /**
     * A shop with a recurrence (one pick-up point "Place du marché") and a basket plan of 1 ×
     * "Panier de légumes" (18 €), open to subscriptions.
     *
     * @param list<array{Product, int}>|null $items product, quantity in thousandths
     */
    private static function basketPlan(?Shop $shop = null, ?array $items = null, RecurringMode $mode = RecurringMode::Basket): RecurringPlan
    {
        $shop ??= self::shopOf(self::subscribedProducer());
        $items ??= [[ProductFactory::createOne(['shop' => $shop, 'name' => 'Panier de légumes', 'priceCents' => 1800]), Quantity::units(1)]];
        $point = DeliveryPointFactory::createOne(['shop' => $shop, 'name' => 'Place du marché']);
        $schedule = self::schedule($shop, array_map(static fn (array $item): Product => $item[0], $items), [$point]);

        $plan = new RecurringPlan($schedule, self::now())
            ->setTitle('Panier de la semaine')
            ->setMode($mode)
            ->setMaxShares(3)
            ->setEnabled(true);
        foreach ($items as $position => [$product, $quantity]) {
            $plan->setItemQuantity($product, $quantity, $position);
        }
        self::entityManager()->persist($plan);
        self::entityManager()->flush();

        return $plan;
    }

    /**
     * An active subscription, from yesterday, at the first pick-up point of the recurrence.
     *
     * @param array<int, int> $quantities product id => thousandths (choice mode)
     */
    private static function subscription(RecurringPlan $plan, ?Customer $customer = null, ?DeliveryPoint $point = null, int $shares = 1, array $quantities = []): RecurringOrder
    {
        $point ??= $plan->getSchedule()->getDeliveryPoints()->first() ?: throw new \LogicException('No pick-up point.');
        $recurringOrder = new RecurringOrder($plan, $customer ?? CustomerFactory::createOne(), $point, self::now()->modify('-1 day'), self::now()->modify('-1 day'));
        $recurringOrder->setShares($shares);
        foreach ($plan->getItems() as $item) {
            $recurringOrder->setQuantityOf($item->getProduct(), $quantities[(int) $item->getProduct()->getId()] ?? 0);
        }
        self::entityManager()->persist($recurringOrder);
        self::entityManager()->flush();

        return $recurringOrder;
    }

    /**
     * An open sale of the recurrence (its next delivery day without a sale), serving its pick-up points and offering
     * the products of the plan.
     *
     * @param array<int, int|null> $maxQuantities product id => stock in thousandths (null = unlimited)
     * @param array<string, mixed> $attributes
     */
    private static function openSale(RecurringPlan $plan, array $maxQuantities = [], array $attributes = []): Sale
    {
        $schedule = $plan->getSchedule();
        // On a delivery day of the recurrence, one sale per day: each new one is delivered a week after the previous one.
        $weeks = \count(self::getContainer()->get(SaleRepository::class)->findBySchedule($schedule));
        $deliveryDate = $schedule->nextDeliveryDates(self::now(), $weeks + 1)[$weeks];
        $sale = SaleFactory::createOne([
            'shop' => $plan->getShop(),
            'deliveryDate' => $deliveryDate,
            'deadlineAt' => $schedule->deadlineAtFor($deliveryDate),
            ...$attributes,
        ]);
        $sale->setSchedule($schedule);

        foreach ($schedule->getDeliveryPoints() as $position => $point) {
            new SaleDelivery($sale, $point, $sale->getDeliveryDate()->setTime(18, 0), $sale->getDeliveryDate()->setTime(19, 0), $position);
        }
        foreach ($plan->getItems() as $item) {
            $product = $item->getProduct();
            new SaleOffer($sale, $product, null, null, $maxQuantities[(int) $product->getId()] ?? null);
        }
        self::entityManager()->flush();

        return $sale;
    }

    private static function generator(): RecurringOrderGenerator
    {
        return self::getContainer()->get(RecurringOrderGenerator::class);
    }

    private static function manager(): RecurringOrderManager
    {
        return self::getContainer()->get(RecurringOrderManager::class);
    }

    private static function schedulePlan(SaleSchedule $schedule): ?RecurringPlan
    {
        return self::entityManager()->getRepository(RecurringPlan::class)->findOneBy(['schedule' => $schedule]);
    }

    /**
     * @return list<RecurringOccurrence>
     */
    private static function occurrencesOf(Sale $sale): array
    {
        self::entityManager()->clear();

        return self::entityManager()->getRepository(RecurringOccurrence::class)->findBy(['sale' => $sale->getId()], ['id' => 'ASC']);
    }

    private static function onlyOccurrence(Sale $sale): RecurringOccurrence
    {
        $occurrences = self::occurrencesOf($sale);
        self::assertCount(1, $occurrences);

        return $occurrences[0];
    }

    /**
     * @return list<string> types of the e-mails sent (NotificationType values), in order
     */
    private static function sentTypes(): array
    {
        $connection = self::getContainer()->get(Connection::class);
        $types = [];
        foreach (self::emails() as $email) {
            $logId = $email->getHeaders()->get(NotificationSender::LOG_HEADER)?->getBodyAsString();
            $type = $connection->fetchOne('SELECT type FROM notification_log WHERE id = ?', [(int) $logId]);
            $types[] = \is_string($type) ? $type : '?';
        }

        return $types;
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T> $collection
     *
     * @return T
     */
    private static function first(Collection $collection): object
    {
        $first = $collection->first();
        self::assertNotFalse($first);

        return $first;
    }

    /**
     * @return list<Email>
     */
    private static function emails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $message): bool => $message instanceof Email));
    }
}
