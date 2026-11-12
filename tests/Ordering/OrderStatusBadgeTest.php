<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Ordering\Enum\OrderStatus;
use App\Tests\Factory\OrderFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

final class OrderStatusBadgeTest extends KernelTestCase
{
    use Factories;

    public function testEachStatusHasItsOwnLook(): void
    {
        $variants = [];

        foreach (OrderStatus::cases() as $status) {
            $order = OrderFactory::createOne(['status' => $status]);
            $badge = new Crawler($this->render(['order' => $order]))->filter('[data-order-status]');

            self::assertCount(1, $badge);
            self::assertSame($status->value, $badge->attr('data-order-status'));
            self::assertSame($status->label(), trim($badge->text()));
            self::assertCount(1, $badge->filter('svg'), 'An icon doubles the colour.');
            $variants[$status->value] = $badge->attr('data-variant');
        }

        self::assertCount(4, array_unique($variants), 'Delivered and cancelled must never look alike.');
    }

    public function testAPlainConfirmedOrderCanBeLeftUnmarked(): void
    {
        $order = OrderFactory::createOne(['status' => OrderStatus::Confirmed]);

        self::assertSame('', trim($this->render(['order' => $order, 'hide_confirmed' => true])));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): string
    {
        return self::getContainer()->get(Environment::class)->render('ordering/_order_status_badge.html.twig', $context);
    }
}
