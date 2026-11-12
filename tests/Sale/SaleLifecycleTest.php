<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Sale\Entity\Sale;
use App\Sale\Enum\SaleStatus;
use App\Sale\Event\SaleCancelled;
use App\Sale\Event\SaleClosed;
use App\Sale\Event\SaleOpened;
use App\Sale\Scheduler\SaleGenerationTask;
use App\Sale\Scheduler\SaleLifecycleTask;
use App\Sale\Service\SaleLifecycle;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

final class SaleLifecycleTest extends KernelTestCase
{
    use Factories;
    use SaleScenario;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->events = [];
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        foreach ([SaleOpened::class, SaleClosed::class, SaleCancelled::class] as $eventClass) {
            $dispatcher->addListener($eventClass, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testOpensTheSalesThatAreDue(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $due = SaleFactory::new()->scheduled()->create(['shop' => $shop, 'opensAt' => new \DateTimeImmutable('-5 minutes')]);
        $later = SaleFactory::new()->scheduled()->create(['shop' => $shop]);

        self::assertSame(1, $this->lifecycle()->openDueSales());

        self::assertSame(SaleStatus::Open, $due->getStatus());
        self::assertSame(SaleStatus::Scheduled, $later->getStatus());
        self::assertSame(SaleStatus::Open, self::reload(Sale::class, $due->getId())->getStatus());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(SaleOpened::class, $this->events[0]);
        self::assertSame($due->getId(), $this->events[0]->sale->getId());

        // Nothing left to open: no new event.
        self::assertSame(0, $this->lifecycle()->openDueSales());
        self::assertCount(1, $this->events);
    }

    public function testDoesNotOpenASaleWhenTheSubscriptionDoesNotAllowNewSales(): void
    {
        $expired = SaleFactory::new()->scheduled()->create([
            'shop' => self::shopOf(self::expiredProducer()),
            'opensAt' => new \DateTimeImmutable('-5 minutes'),
        ]);
        $unsubscribed = SaleFactory::new()->scheduled()->create(['opensAt' => new \DateTimeImmutable('-5 minutes')]);

        self::assertSame(0, $this->lifecycle()->openDueSales());
        self::assertFalse($this->lifecycle()->openIfDue($expired));

        self::assertSame(SaleStatus::Scheduled, $expired->getStatus());
        self::assertSame(SaleStatus::Scheduled, $unsubscribed->getStatus());
        self::assertSame([], $this->events);
    }

    public function testASaleInProgressGoesToItsEndEvenWithoutSubscription(): void
    {
        $shop = self::shopOf(self::expiredProducer());
        $open = SaleFactory::createOne(['shop' => $shop, 'deadlineAt' => new \DateTimeImmutable('-1 minute')]);
        $closed = SaleFactory::new()->closed()->create(['shop' => $shop, 'deliveryDate' => new \DateTimeImmutable('-1 day')]);

        self::assertSame(1, $this->lifecycle()->closeDueSales());
        self::assertSame(1, $this->lifecycle()->finishDueSales());

        self::assertSame(SaleStatus::Closed, $open->getStatus());
        self::assertSame(SaleStatus::Done, $closed->getStatus());
    }

    public function testClosesTheSalesWhoseDeadlineHasPassed(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $over = SaleFactory::createOne(['shop' => $shop, 'deadlineAt' => new \DateTimeImmutable('-1 minute')]);
        $running = SaleFactory::createOne(['shop' => $shop]);

        self::assertSame(1, $this->lifecycle()->closeDueSales());

        self::assertSame(SaleStatus::Closed, self::reload(Sale::class, $over->getId())->getStatus());
        self::assertSame(SaleStatus::Open, self::reload(Sale::class, $running->getId())->getStatus());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(SaleClosed::class, $this->events[0]);
        self::assertSame($over->getId(), $this->events[0]->sale->getId());
    }

    public function testFinishesTheSalesTheDayAfterTheDelivery(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $deliveredYesterday = SaleFactory::new()->closed()->create(['shop' => $shop, 'deliveryDate' => new \DateTimeImmutable('-1 day')]);
        $deliveredToday = SaleFactory::new()->closed()->create(['shop' => $shop, 'deliveryDate' => new \DateTimeImmutable('today')]);
        $stillOpen = SaleFactory::createOne(['shop' => $shop]);

        self::assertSame(1, $this->lifecycle()->finishDueSales());

        self::assertSame(SaleStatus::Done, self::reload(Sale::class, $deliveredYesterday->getId())->getStatus());
        self::assertSame(SaleStatus::Closed, self::reload(Sale::class, $deliveredToday->getId())->getStatus());
        self::assertSame(SaleStatus::Open, self::reload(Sale::class, $stillOpen->getId())->getStatus());
        self::assertSame([], $this->events);
    }

    public function testCancelDispatchesSaleCancelled(): void
    {
        $sale = SaleFactory::createOne();

        $this->lifecycle()->cancel($sale);

        self::assertSame(SaleStatus::Cancelled, self::reload(Sale::class, $sale->getId())->getStatus());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(SaleCancelled::class, $this->events[0]);
        self::assertSame($sale->getId(), $this->events[0]->sale->getId());
    }

    public function testADoneOrCancelledSaleCannotBeCancelled(): void
    {
        $done = SaleFactory::new()->done()->create();
        $cancelled = SaleFactory::new()->cancelled()->create();

        foreach ([$done, $cancelled] as $sale) {
            try {
                $this->lifecycle()->cancel($sale);
                self::fail('A finished sale must not be cancelled.');
            } catch (\DomainException) {
            }
        }

        self::assertSame(SaleStatus::Done, $done->getStatus());
        self::assertSame([], $this->events);
    }

    public function testCancelsTheSalesThatNeverOpened(): void
    {
        $missed = SaleFactory::new()->scheduled()->create([
            'shop' => self::shopOf(self::expiredProducer()),
            'opensAt' => new \DateTimeImmutable('-3 days'),
            'deadlineAt' => new \DateTimeImmutable('-1 hour'),
        ]);
        $upcoming = SaleFactory::new()->scheduled()->create();

        self::assertSame(1, $this->lifecycle()->cancelMissedSales());

        self::assertSame(SaleStatus::Cancelled, $missed->getStatus());
        self::assertSame(SaleStatus::Scheduled, $upcoming->getStatus());
        self::assertCount(1, $this->events);
        self::assertInstanceOf(SaleCancelled::class, $this->events[0]);
    }

    public function testTheScheduledTaskRunsTheWholeCycle(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        $toOpen = SaleFactory::new()->scheduled()->create(['shop' => $shop, 'opensAt' => new \DateTimeImmutable('-5 minutes')]);
        $toClose = SaleFactory::createOne(['shop' => $shop, 'deadlineAt' => new \DateTimeImmutable('-1 minute')]);
        $toFinish = SaleFactory::new()->closed()->create(['shop' => $shop, 'deliveryDate' => new \DateTimeImmutable('-2 days')]);

        $result = self::getContainer()->get(SaleLifecycleTask::class)();

        self::assertSame(['opened' => 1, 'closed' => 1, 'finished' => 1, 'missed' => 0], $result);
        self::assertSame(SaleStatus::Open, $toOpen->getStatus());
        self::assertSame(SaleStatus::Closed, $toClose->getStatus());
        self::assertSame(SaleStatus::Done, $toFinish->getStatus());
    }

    public function testTheTickCommandRunsTheCycle(): void
    {
        $sale = SaleFactory::new()->scheduled()->create([
            'shop' => self::shopOf(self::subscribedProducer()),
            'opensAt' => new \DateTimeImmutable('-5 minutes'),
        ]);

        $kernel = self::$kernel ?? throw new \LogicException('The kernel is not booted.');
        $tester = new CommandTester(new Application($kernel)->find('app:sales:tick'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 opened', $tester->getDisplay());
        self::assertSame(SaleStatus::Open, self::reload(Sale::class, $sale->getId())->getStatus());
    }

    public function testTheGenerateCommandAndTaskCreateTheComingSales(): void
    {
        $shop = self::shopOf(self::subscribedProducer());
        self::schedule($shop, [ProductFactory::createOne(['shop' => $shop])]);

        $kernel = self::$kernel ?? throw new \LogicException('The kernel is not booted.');
        $tester = new CommandTester(new Application($kernel)->find('app:sales:generate'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('4 sale(s) generated', $tester->getDisplay());
        self::assertSame(0, self::getContainer()->get(SaleGenerationTask::class)());
    }

    private function lifecycle(): SaleLifecycle
    {
        return self::getContainer()->get(SaleLifecycle::class);
    }
}
