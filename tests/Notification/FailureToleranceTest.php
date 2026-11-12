<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Enum\NotificationType;
use App\Notification\Service\NotificationSender;
use App\Ordering\Event\OrderPlaced;
use App\Sale\Event\SaleOpened;
use App\Shared\Enum\Actor;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ShopFollowerFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * A notification problem is logged, never propagated to the business action that dispatched the event.
 */
final class FailureToleranceTest extends KernelTestCase
{
    use Factories;
    use NotificationScenario;

    public function testAMailerErrorDoesNotBreakTheDispatcherAndIsLoggedAsFailed(): void
    {
        $order = $this->createOrder();
        $breakMailer = static function (): never {
            throw new TransportException('SMTP is down.');
        };
        $this->eventDispatcher()->addListener(MessageEvent::class, $breakMailer, 1024);

        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame('order_placed', $logs[0]->getType());
        self::assertSame(NotificationStatus::Failed, $logs[0]->getStatus());
        self::assertSame($order->getCustomer()->getEmail(), $logs[0]->getRecipient());

        // A failed attempt does not count as "already sent": the next dispatch goes through.
        $this->eventDispatcher()->removeListener(MessageEvent::class, $breakMailer);
        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        self::assertSame([$order->getCustomer()->getEmail()], $this->recipients());
        self::assertSame(
            [NotificationStatus::Failed, NotificationStatus::Sent],
            array_map(static fn (NotificationLog $log): NotificationStatus => $log->getStatus(), $this->logs()),
        );
    }

    public function testOneFailingRecipientDoesNotPreventTheOthers(): void
    {
        $sale = $this->createSale();
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'broken@example.test'])]);
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'fine@example.test'])]);
        $this->eventDispatcher()->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            if ('broken@example.test' === $event->getEnvelope()->getRecipients()[0]->getAddress()) {
                throw new TransportException('Mailbox unavailable.');
            }
        }, 1024);

        $this->dispatch(new SaleOpened($sale));

        self::assertSame(['fine@example.test'], $this->recipients());
        $statuses = [];
        foreach ($this->logs() as $log) {
            $statuses[(string) $log->getRecipient()] = $log->getStatus();
        }
        self::assertSame(NotificationStatus::Failed, $statuses['broken@example.test']);
        self::assertSame(NotificationStatus::Sent, $statuses['fine@example.test']);
    }

    public function testAnErrorWhileComposingTheMessageIsToleratedToo(): void
    {
        $order = $this->createOrder();
        $customer = $order->getCustomer();

        $sent = self::getContainer()->get(NotificationSender::class)->send(
            NotificationType::OrderUpdated,
            new Address((string) $customer->getEmail()),
            'order',
            $order->getId(),
            static fn (): never => throw new \RuntimeException('Template error.'),
            $customer,
        );

        self::assertFalse($sent);
        self::assertSame([], $this->emails());
        self::assertSame(NotificationStatus::Failed, $this->logs()[0]->getStatus());
    }

    public function testTheDeliveryOutcomeReportedByTheWorkerUpdatesTheLog(): void
    {
        $order = $this->createOrder();
        $this->dispatch(new OrderPlaced($order, Actor::Customer));
        $email = $this->onlyEmail();
        self::assertSame(NotificationStatus::Sent, $this->logs()[0]->getStatus());

        // The asynchronous transport fails…
        $this->eventDispatcher()->dispatch(new FailedMessageEvent($email, new TransportException('Rejected.')));
        self::assertSame(NotificationStatus::Failed, $this->logs()[0]->getStatus());

        // … then a retry succeeds.
        $this->eventDispatcher()->dispatch(new SentMessageEvent(new SentMessage($email, Envelope::create($email))));
        self::assertSame(NotificationStatus::Sent, $this->logs()[0]->getStatus());
    }

    public function testTheUniqueKeyStopsAConcurrentSecondSend(): void
    {
        $order = $this->createOrder();
        $email = (string) $order->getCustomer()->getEmail();
        // Another process is sending the same message right now: its row holds the key, but is not
        // visible as "sent" to the quick check yet.
        self::getContainer()->get(Connection::class)->insert('notification_log', [
            'type' => NotificationType::OrderPlaced->value,
            'channel' => 'email',
            'status' => NotificationStatus::Skipped->value,
            'recipient' => $email,
            'related_type' => 'order',
            'related_id' => $order->getId(),
            'sent_at' => '2026-11-10 10:00:00',
            'dedup_key' => NotificationSender::dedupKey(NotificationType::OrderPlaced, 'order', (int) $order->getId(), $email),
        ]);

        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        self::assertSame([], $this->emails());
        self::assertCount(1, $this->logs());
    }

    public function testSendingNeverFlushesTheCallersUnitOfWork(): void
    {
        $order = $this->createOrder();
        $order->setNote('Pas encore enregistrée');

        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        self::assertCount(1, $this->emails());
        $stored = self::getContainer()->get(Connection::class)->fetchOne('SELECT note FROM customer_order WHERE id = ?', [$order->getId()]);
        self::assertNotSame('Pas encore enregistrée', $stored);
    }

    public function testMessagesWithoutNotificationHeaderAreIgnored(): void
    {
        $this->createOrder();
        $foreign = new Email()->from('a@example.test')->to('b@example.test')->subject('Hello')->text('Hello');

        $this->eventDispatcher()->dispatch(new FailedMessageEvent($foreign, new TransportException('Rejected.')));

        self::assertSame([], $this->logs());
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        return self::getContainer()->get(EventDispatcherInterface::class);
    }
}
