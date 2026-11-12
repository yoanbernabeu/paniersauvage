<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use App\Ordering\Event\OrderCancelled;
use App\Ordering\Event\OrderPlaced;
use App\Ordering\Event\OrderUpdated;
use App\Sale\Event\SaleCancelled;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Tests\Factory\DeliveryPointFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Part\DataPart;
use Zenstruck\Foundry\Test\Factories;

final class OrderNotificationTest extends KernelTestCase
{
    use Factories;
    use NotificationScenario;

    public function testOrderPlacedSendsTheConfirmationToTheCustomer(): void
    {
        $order = $this->createOrder(customer: ['email' => 'camille@example.test']);
        $delivery = $order->getSaleDelivery();
        $formatter = new FrenchFormatter();

        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        self::assertQueuedEmailCount(1);
        $email = $this->onlyEmail();
        self::assertSame(['camille@example.test'], $this->recipients());
        self::assertEmailSubjectContains($email, 'Ta commande chez Fournil du Pont est confirmée');
        self::assertEmailAddressContains($email, 'From', 'bonjour@panier-sauvage.test');

        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, 'Bonjour Camille,');
            self::$assert($email, 'Ta commande chez Fournil du Pont est confirmée.');
            self::$assert($email, $order->getReference());
            self::$assert($email, '2 × Pain de campagne');
            self::$assert($email, 'À régler à la livraison');
            self::$assert($email, $this->money('10,40'));
            self::$assert($email, 'Moyens de paiement acceptés par Fournil du Pont : Wero au 06 11 22 33 44, espèces');
            self::$assert($email, ucfirst($formatter->dateLong($delivery->getStartsAt())));
            self::$assert($email, $formatter->timeRange($delivery->getStartsAt(), $delivery->getEndsAt()));
            self::$assert($email, 'Place du village');
            self::$assert($email, '1 place de la Mairie');
            self::$assert($email, 'modifier ou annuler ta commande jusqu\'au');
            self::$assert($email, $formatter->dateTimeLong($order->getSale()->getDeadlineAt()));
            self::$assert($email, 'http://localhost:8000/fournil-du-pont');
        }
    }

    public function testOrderPlacedIsLoggedAndSentOnlyOnce(): void
    {
        $order = $this->createOrder();

        $this->dispatch(new OrderPlaced($order, Actor::Customer));
        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        self::assertQueuedEmailCount(1);
        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame('order_placed', $logs[0]->getType());
        self::assertSame($order->getCustomer(), $logs[0]->getCustomer());
        self::assertSame($order->getCustomer()->getEmail(), $logs[0]->getRecipient());
        self::assertSame('order', $logs[0]->getRelatedType());
        self::assertSame($order->getId(), $logs[0]->getRelatedId());
        self::assertSame(NotificationStatus::Sent, $logs[0]->getStatus());
        self::assertSame(NotificationChannel::Email, $logs[0]->getChannel());
    }

    public function testConfirmationNeverMentionsAReceivedPaymentNorAnInvoice(): void
    {
        $this->dispatch(new OrderPlaced($this->createOrder(), Actor::Customer));

        $email = $this->onlyEmail();
        foreach (['payé', 'Payé', 'facture', 'Facture', 'paiement reçu', 'Paiement reçu', 'encaiss'] as $forbidden) {
            self::assertEmailHtmlBodyNotContains($email, $forbidden);
            self::assertEmailTextBodyNotContains($email, $forbidden);
        }
    }

    public function testConfirmationCarriesTheDeliverySlotAsCalendarFile(): void
    {
        $order = $this->createOrder();

        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        $attachments = $this->onlyEmail()->getAttachments();
        self::assertCount(1, $attachments);
        self::assertInstanceOf(DataPart::class, $attachments[0]);
        self::assertSame('livraison.ics', $attachments[0]->getFilename());
        self::assertSame('text/calendar', $attachments[0]->getContentType());

        $ics = $attachments[0]->getBody();
        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('SUMMARY:Livraison Fournil du Pont', $ics);
        self::assertStringContainsString('LOCATION:Place du village\, 1 place de la Mairie', $ics);
        self::assertStringContainsString(
            'DTSTART:'.$order->getSaleDelivery()->getStartsAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            $ics,
        );
    }

    public function testOrderPlacedByTheProducerSaysSo(): void
    {
        $this->dispatch(new OrderPlaced($this->createOrder(), Actor::Producer));

        $email = $this->onlyEmail();
        self::assertEmailHtmlBodyContains($email, 'Fournil du Pont a enregistré une commande pour toi.');
        self::assertEmailTextBodyContains($email, 'Fournil du Pont a enregistré une commande pour toi.');
    }

    public function testHomeDeliveryShowsTheCustomerAddressAndNoEditDeadlineOnceTheSaleIsClosed(): void
    {
        $sale = $this->createSale();
        $sale->close();
        $home = SaleDeliveryFactory::createOne(['sale' => $sale, 'deliveryPoint' => DeliveryPointFactory::new(['shop' => $sale->getShop()])->home()]);
        $order = $this->createOrder($sale);
        $order->setSaleDelivery($home)->setDeliveryAddress('12 rue des Lilas, Pontville');

        $this->dispatch(new OrderPlaced($order, Actor::Producer));

        $email = $this->onlyEmail();
        self::assertEmailHtmlBodyContains($email, 'Livraison à domicile : 12 rue des Lilas, Pontville');
        self::assertEmailTextBodyContains($email, 'Livraison à domicile : 12 rue des Lilas, Pontville');
        self::assertEmailHtmlBodyNotContains($email, 'modifier ou annuler ta commande jusqu');
        self::assertEmailHtmlBodyContains($email, 'Pour changer ta commande, contacte directement Fournil du Pont au 06 11 22 33 44.');
    }

    /**
     * @return iterable<string, array{Actor, string, string}>
     */
    public static function updateWordings(): iterable
    {
        yield 'customer' => [Actor::Customer, 'Ta commande chez Fournil du Pont est modifiée', 'Tu as modifié ta commande chez Fournil du Pont.'];
        yield 'producer' => [Actor::Producer, 'Fournil du Pont a modifié ta commande', 'Fournil du Pont a modifié ta commande.'];
        yield 'system' => [Actor::System, 'Ta commande chez Fournil du Pont est modifiée', 'Ta commande chez Fournil du Pont a été mise à jour.'];
    }

    #[DataProvider('updateWordings')]
    public function testOrderUpdatedWordingDependsOnTheActor(Actor $actor, string $subject, string $sentence): void
    {
        $order = $this->createOrder(quantity: 3);

        $this->dispatch(new OrderUpdated($order, $actor));

        $email = $this->onlyEmail();
        self::assertSame([$order->getCustomer()->getEmail()], $this->recipients());
        self::assertEmailSubjectContains($email, $subject);
        self::assertEmailHtmlBodyContains($email, $sentence);
        self::assertEmailTextBodyContains($email, $sentence);
        self::assertEmailHtmlBodyContains($email, '3 × Pain de campagne');
        self::assertEmailHtmlBodyContains($email, $this->money('15,60'));
        self::assertEmailTextBodyContains($email, 'À régler à la livraison : '.$this->money('15,60'));
    }

    public function testEveryUpdateSendsANewSummary(): void
    {
        $order = $this->createOrder();

        $this->dispatch(new OrderUpdated($order, Actor::Customer));
        $this->dispatch(new OrderUpdated($order, Actor::Producer));

        self::assertQueuedEmailCount(2);
        self::assertSame(['order_updated', 'order_updated'], array_map(static fn (NotificationLog $log): string => $log->getType(), $this->logs()));
    }

    /**
     * @return iterable<string, array{Actor, string, string}>
     */
    public static function cancellationWordings(): iterable
    {
        yield 'customer' => [Actor::Customer, 'Ta commande chez Fournil du Pont est annulée', 'Tu as annulé ta commande'];
        yield 'producer' => [Actor::Producer, 'Fournil du Pont a annulé ta commande', 'Fournil du Pont a annulé ta commande'];
        yield 'system' => [Actor::System, 'Ta commande chez Fournil du Pont est annulée', 'a été annulée.'];
    }

    #[DataProvider('cancellationWordings')]
    public function testOrderCancelledWordingDependsOnTheActor(Actor $actor, string $subject, string $sentence): void
    {
        $order = $this->createOrder();
        $order->cancel();

        $this->dispatch(new OrderCancelled($order, $actor));
        $this->dispatch(new OrderCancelled($order, $actor));

        $email = $this->onlyEmail();
        self::assertSame([$order->getCustomer()->getEmail()], $this->recipients());
        self::assertEmailSubjectContains($email, $subject);
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, $sentence);
            self::$assert($email, $order->getReference());
            self::$assert($email, 'Tu n\'as rien à régler');
        }
        self::assertSame('order_cancelled', $this->logs()[0]->getType());
    }

    public function testNoOrderCancellationEmailWhenTheSaleCancellationWasAlreadyAnnounced(): void
    {
        $order = $this->createOrder();

        $this->dispatch(new SaleCancelled($order->getSale()));
        $this->dispatch(new OrderCancelled($order, Actor::Producer));

        self::assertQueuedEmailCount(1);
        self::assertSame(['sale_cancelled'], array_map(static fn (NotificationLog $log): string => $log->getType(), $this->logs()));
    }
}
