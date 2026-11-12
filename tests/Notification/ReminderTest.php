<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Scheduler\SendRemindersMessage;
use App\Notification\Scheduler\SendRemindersTask;
use App\Sale\Entity\Sale;
use App\Shop\Entity\Shop;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

final class ReminderTest extends KernelTestCase
{
    use Factories;
    use NotificationScenario;

    public function testRemindsCustomersWhoseConfirmedOrderIsDeliveredTomorrow(): void
    {
        $shop = $this->createShop();
        $tomorrow = $this->saleDeliveredIn('tomorrow', $shop);
        $order = $this->createOrder($tomorrow, 2, ['email' => 'camille@example.test']);
        $this->createOrder($tomorrow, 1, ['email' => 'cancelled@example.test'], OrderFactory::new()->cancelled());
        $this->createOrder($tomorrow, 1, ['email' => 'delivered@example.test'], OrderFactory::new()->delivered());
        $this->createOrder($this->saleDeliveredIn('today', $shop), 1, ['email' => 'today@example.test']);
        $this->createOrder($this->saleDeliveredIn('+2 days', $shop), 1, ['email' => 'later@example.test']);
        $this->createOrder($this->saleDeliveredIn('tomorrow', $shop, SaleFactory::new()->cancelled()), 1, ['email' => 'cancelled-sale@example.test']);

        $tester = $this->execute();

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 reminder(s) sent.', $tester->getDisplay());
        self::assertQueuedEmailCount(1);
        self::assertSame(['camille@example.test'], $this->recipients());

        $email = $this->onlyEmail();
        self::assertEmailSubjectContains($email, 'Rappel : ta commande chez Fournil du Pont, c\'est demain');
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, 'ta commande chez Fournil du Pont t\'attend demain');
            self::$assert($email, 'Place du village');
            self::$assert($email, '18'.self::NBSP.'h – 19'.self::NBSP.'h');
            self::$assert($email, '2 × Pain de campagne');
            self::$assert($email, 'À régler à la livraison');
            self::$assert($email, $this->money('10,40'));
            self::$assert($email, 'Wero au 06 11 22 33 44, espèces');
        }

        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame('order_reminder', $logs[0]->getType());
        self::assertSame('order', $logs[0]->getRelatedType());
        self::assertSame($order->getId(), $logs[0]->getRelatedId());
    }

    public function testRunningTheReminderTwiceSendsNothingMore(): void
    {
        $this->createOrder($this->saleDeliveredIn('tomorrow'));

        $this->execute();
        $second = $this->execute();
        self::getContainer()->get(SendRemindersTask::class)();

        self::assertStringContainsString('0 reminder(s) sent.', $second->getDisplay());
        self::assertQueuedEmailCount(1);
        self::assertCount(1, $this->logs());
    }

    public function testTheScheduledMessageSendsTheReminders(): void
    {
        $this->createOrder($this->saleDeliveredIn('tomorrow'));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SendRemindersMessage());

        self::assertQueuedEmailCount(1);
    }

    public function testTheReminderIsScheduledEveryDay(): void
    {
        $tester = $this->execute('debug:scheduler');

        self::assertStringContainsString('SendRemindersMessage', $tester->getDisplay());
        self::assertStringContainsString('every day at 10:00 (Europe/Paris)', $tester->getDisplay());
    }

    private function saleDeliveredIn(string $when, ?Shop $shop = null, ?SaleFactory $factory = null): Sale
    {
        return $this->createSale($shop, ['deliveryDate' => new \DateTimeImmutable($when)], $factory ?? SaleFactory::new()->closed());
    }

    private function execute(string $name = 'app:notifications:send-reminders'): CommandTester
    {
        // Reuse the kernel the factories already booted: a reboot would lose the collected e-mails.
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find($name));
        $tester->execute([]);

        return $tester;
    }
}
