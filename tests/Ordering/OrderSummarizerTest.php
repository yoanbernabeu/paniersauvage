<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductUnit;
use App\Customer\Entity\Customer;
use App\Delivery\Entity\DeliveryPoint;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Service\OrderSummarizer;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleDelivery;
use App\Sale\Entity\SaleOffer;
use App\Shared\Service\Quantity;
use App\Shop\Entity\Shop;
use PHPUnit\Framework\TestCase;

final class OrderSummarizerTest extends TestCase
{
    private Sale $sale;
    private SaleDelivery $delivery;
    private SaleOffer $bread;
    private SaleOffer $brioche;

    protected function setUp(): void
    {
        $shop = new Shop('Fournil du Pont', 'fournil-du-pont');
        $this->sale = new Sale($shop, 'ABC234', new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-09 08:00'), new \DateTimeImmutable('2026-11-13 20:00'), new \DateTimeImmutable('2026-11-02 10:00'));
        $this->sale->open()->close();
        $this->delivery = new SaleDelivery($this->sale, new DeliveryPoint($shop, 'Place du village'), new \DateTimeImmutable('2026-11-16 18:00'), new \DateTimeImmutable('2026-11-16 19:00'));
        $this->bread = new SaleOffer($this->sale, new Product($shop, 'Pain de campagne', 500));
        $this->brioche = new SaleOffer($this->sale, new Product($shop, 'Brioche', 700));
    }

    public function testDeliverySheetSplitsTheOrdersAndSumsTheTruckLoad(): void
    {
        $jeanne = $this->order('jeanne', [[$this->bread, 2], [$this->brioche, 1]]);
        $paul = $this->order('paul', [[$this->bread, 1]])->markDelivered();
        $lucie = $this->order('lucie', [[$this->bread, 3]])->markNotDelivered();
        $marc = $this->order('marc', [[$this->brioche, 2]]);

        $sheet = new OrderSummarizer()->deliverySheet([$lucie, $jeanne, $paul, $marc]);

        // Confirmed orders first, the ones marked "not delivered" at the bottom.
        self::assertSame([$jeanne, $marc, $lucie], $sheet->todo);
        self::assertSame([$paul], $sheet->done);
        self::assertSame([
            ['offer' => 0, 'label' => 'Pain de campagne', 'unit' => ProductUnit::Piece, 'total' => 6000, 'remaining' => 5000],
            ['offer' => 0, 'label' => 'Brioche', 'unit' => ProductUnit::Piece, 'total' => 3000, 'remaining' => 3000],
        ], $sheet->truck);
    }

    public function testDeliverySheetOfNoOrderIsEmpty(): void
    {
        $sheet = new OrderSummarizer()->deliverySheet([]);

        self::assertSame([], $sheet->todo);
        self::assertSame([], $sheet->done);
        self::assertSame([], $sheet->truck);
    }

    public function testTotalLeavesCancelledOrdersOut(): void
    {
        $orders = [
            $this->order('a', [[$this->bread, 2]]),
            $this->order('b', [[$this->brioche, 1]])->markDelivered(),
            $this->order('c', [[$this->brioche, 5]])->cancel(),
        ];

        self::assertSame(1000 + 700, new OrderSummarizer()->totalCents($orders));
        self::assertSame(0, new OrderSummarizer()->totalCents([]));
    }

    /**
     * @param list<array{SaleOffer, int}> $lines
     */
    private function order(string $name, array $lines): Order
    {
        $order = new Order($this->sale, new Customer($name.'@example.test'), $this->delivery, 'PS-'.strtoupper($name), new \DateTimeImmutable('2026-11-10 09:00'));
        foreach ($lines as [$offer, $quantity]) {
            new OrderLine($order, $offer, Quantity::units($quantity));
        }

        return $order;
    }
}
