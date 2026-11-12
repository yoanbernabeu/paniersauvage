<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Ordering\Service\OrderIcsBuilder;
use App\Ordering\Service\OrderManager;
use App\Shared\Enum\Actor;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class OrderIcsBuilderTest extends KernelTestCase
{
    use Factories;
    use OrderingFixtures;

    public function testBuildsACalendarEventForTheDeliverySlot(): void
    {
        $shop = $this->shopOf($this->createProducer());
        $shop->setName('Le Fournil du Pont')->setPaymentInfo('Wero au 06 12 34 56 78, espèces');
        $sale = SaleFactory::createOne(['shop' => $shop, 'deliveryDate' => new \DateTimeImmutable('2030-01-14')]);
        $delivery = SaleDeliveryFactory::createOne([
            'sale' => $sale,
            'deliveryPoint' => DeliveryPointFactory::new(['shop' => $shop, 'name' => 'Place du village', 'address' => '1 place de la Mairie, 26400 Saoû']),
            'startsAt' => new \DateTimeImmutable('2030-01-14 18:00'),
            'endsAt' => new \DateTimeImmutable('2030-01-14 19:00'),
        ]);
        $brioche = $this->createOffer($sale, 'Brioche; nature', 650, 5);
        $baguette = $this->createOffer($sale, 'Baguette', 120);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 2, self::idOf($baguette) => 1], $delivery);

        $ics = self::getContainer()->get(OrderIcsBuilder::class)->build($order);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);
        self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));

        // Lines are folded at 75 octets; unfold before reading the properties.
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line));
        }
        $unfolded = str_replace("\r\n ", '', $ics);

        // 18:00–19:00 in Paris in January is 17:00–18:00 UTC.
        self::assertStringContainsString("DTSTART:20300114T170000Z\r\n", $unfolded);
        self::assertStringContainsString("DTEND:20300114T180000Z\r\n", $unfolded);
        self::assertStringContainsString('UID:'.mb_strtolower($order->getReference())."@panier-sauvage\r\n", $unfolded);
        self::assertStringContainsString("SUMMARY:Livraison Le Fournil du Pont\r\n", $unfolded);
        self::assertStringContainsString("LOCATION:Place du village\\, 1 place de la Mairie\\, 26400 Saoû\r\n", $unfolded);
        self::assertStringContainsString('2 × Brioche\; nature\n1 × Baguette\n', $unfolded);
        self::assertStringContainsString('À régler à la livraison : 14\,20 €', $unfolded);
        self::assertStringContainsString('Wero au 06 12 34 56 78\, espèces', $unfolded);
        self::assertStringContainsString("STATUS:CONFIRMED\r\n", $unfolded);
    }

    public function testHomeDeliveryUsesTheCustomerAddressAndCancelledOrdersAreFlagged(): void
    {
        $sale = $this->createSale($this->shopOf($this->createProducer()));
        $home = $this->addHomeDelivery($sale);
        $brioche = $this->createOffer($sale, 'Brioche', 650, 5);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($brioche) => 1], $home, "12 route des Écoliers\n26400 Saoû");
        self::getContainer()->get(OrderManager::class)->cancelOrder($order, Actor::Producer);

        $unfolded = str_replace("\r\n ", '', self::getContainer()->get(OrderIcsBuilder::class)->build($order));

        self::assertStringContainsString("LOCATION:12 route des Écoliers\\n26400 Saoû\r\n", $unfolded);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $unfolded);
    }
}
