<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Entity\Product;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    private Sale $sale;
    private SaleOffer $bread;
    private SaleOffer $baguette;
    private Order $order;

    protected function setUp(): void
    {
        $shop = new Shop('Fournil du Pont', 'fournil-du-pont');
        $this->sale = new Sale($shop, 'ABC234', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-09 08:00'), new \DateTimeImmutable('2026-11-13 20:00'), new \DateTimeImmutable('2026-11-02 10:00'))->open();
        $delivery = new SaleDelivery($this->sale, new DeliveryPoint($shop, "Place de l'église"), new \DateTimeImmutable('2026-11-16 18:00'), new \DateTimeImmutable('2026-11-16 19:00'));
        $this->bread = new SaleOffer($this->sale, new Product($shop, 'Pain de campagne', 520));
        $this->baguette = new SaleOffer($this->sale, new Product($shop, 'Baguette tradition', 130)->setVatRateBp(550));
        $this->order = new Order($this->sale, new Customer('Lucie@Example.test'), $delivery, 'PS-ABCD-2345', new \DateTimeImmutable('2026-11-10 09:00'));
    }

    public function testANewOrderIsConfirmedAndEmpty(): void
    {
        self::assertSame(OrderStatus::Confirmed, $this->order->getStatus());
        self::assertSame(0, $this->order->getTotalCents());
        self::assertSame(0, $this->order->getItemCount());
        self::assertEquals($this->order->getCreatedAt(), $this->order->getUpdatedAt());
        self::assertSame('lucie@example.test', $this->order->getCustomer()->getEmail());
        self::assertSame($this->sale->getShop(), $this->order->getShop());
    }

    public function testTotalFollowsTheLines(): void
    {
        $breadLine = new OrderLine($this->order, $this->bread, 2000);
        $baguetteLine = new OrderLine($this->order, $this->baguette, 3000);

        self::assertSame(2 * 520 + 3 * 130, $this->order->getTotalCents());
        self::assertSame(5, $this->order->getItemCount());
        self::assertSame($breadLine, $this->order->getLineFor($this->bread));

        $baguetteLine->setQuantity(1000);
        self::assertSame(2 * 520 + 130, $this->order->getTotalCents());

        $this->order->removeLine($breadLine);
        self::assertSame(130, $this->order->getTotalCents());
        self::assertSame(130, $this->order->recomputeTotal());
        self::assertNull($this->order->getLineFor($this->bread));
    }

    public function testLinesFreezePriceVatAndLabel(): void
    {
        $line = new OrderLine($this->order, $this->bread, 2000);

        $this->bread->setPriceCents(600)->setVatRateBp(2000);
        $this->bread->getProduct()->setName('Pain de campagne XL');

        self::assertSame(520, $line->getUnitPriceCents());
        self::assertSame(550, $line->getVatRateBp());
        self::assertSame('Pain de campagne', $line->getLabel());
        self::assertSame(1040, $line->getTotalCents());
        self::assertSame(1040, $this->order->recomputeTotal());
    }

    public function testStatusHelpers(): void
    {
        self::assertTrue($this->order->isActive());
        self::assertTrue($this->order->markDelivered()->isDelivered());
        self::assertSame(OrderStatus::NotDelivered, $this->order->markNotDelivered()->getStatus());
        self::assertTrue($this->order->isActive());
        self::assertTrue($this->order->reconfirm()->isConfirmed());
        self::assertTrue($this->order->cancel()->isCancelled());
        self::assertFalse($this->order->isActive());
    }

    public function testACancelledOrderNoLongerChanges(): void
    {
        $this->order->cancel();

        foreach ([
            $this->order->cancel(...),
            $this->order->markDelivered(...),
            $this->order->markNotDelivered(...),
            $this->order->reconfirm(...),
        ] as $transition) {
            try {
                $transition();
                self::fail('A cancelled order must not change status.');
            } catch (\DomainException) {
                self::assertTrue($this->order->isCancelled());
            }
        }
    }

    public function testAConfirmedOrderCannotBeReconfirmed(): void
    {
        $this->expectException(\DomainException::class);

        $this->order->reconfirm();
    }

    public function testThereIsNoPaymentStatus(): void
    {
        $values = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());

        self::assertSame(['confirmed', 'delivered', 'not_delivered', 'cancelled'], $values);
    }

    public function testCustomerCanEditOnlyAConfirmedOrderOfAnEditableSale(): void
    {
        $beforeDeadline = new \DateTimeImmutable('2026-11-12 10:00');
        $afterDeadline = new \DateTimeImmutable('2026-11-14 10:00');

        self::assertTrue($this->order->isCustomerEditable($beforeDeadline));
        self::assertFalse($this->order->isCustomerEditable($afterDeadline));
        self::assertFalse($this->order->cancel()->isCustomerEditable($beforeDeadline));
    }

    public function testHistoryAndTouch(): void
    {
        $at = new \DateTimeImmutable('2026-11-11 12:00');
        $event = $this->order->recordEvent(OrderEventType::Updated, Actor::Producer, ['reason' => 'rupture'], $at);
        $this->order->touch($at);

        self::assertCount(1, $this->order->getEvents());
        self::assertSame($this->order, $event->getOrder());
        self::assertSame(Actor::Producer, $event->getActor());
        self::assertSame(['reason' => 'rupture'], $event->getDetail());
        self::assertSame($at, $event->getCreatedAt());
        self::assertSame($at, $this->order->getUpdatedAt());
    }
}
