<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Enum\NotificationStatus;
use App\Ordering\Event\OrderPlaced;
use App\Sale\Event\SaleCancelled;
use App\Sale\Event\SaleClosed;
use App\Sale\Event\SaleOpened;
use App\Shared\Enum\Actor;
use App\Shared\Service\FrenchFormatter;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\ShopFollowerFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class SaleNotificationTest extends KernelTestCase
{
    use Factories;
    use NotificationScenario;

    public function testSaleOpenedIsAnnouncedToConsentingFollowersOnly(): void
    {
        $sale = $this->createSale();
        $shop = $sale->getShop();
        ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'alice@example.test', 'firstName' => 'Alice'])]);
        ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'bob@example.test'])]);
        ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'no-consent@example.test']), 'emailConsent' => false]);
        ShopFollowerFactory::createOne(['customer' => CustomerFactory::new(['email' => 'other-shop@example.test'])]);
        $formatter = new FrenchFormatter();

        $this->dispatch(new SaleOpened($sale));

        self::assertQueuedEmailCount(2);
        self::assertSame(['alice@example.test', 'bob@example.test'], $this->recipients());

        $email = $this->emails()[0];
        self::assertCount(1, $email->getTo());
        self::assertEmailSubjectContains($email, 'Fournil du Pont ouvre ses commandes pour '.$formatter->dateLong($sale->getDeliveryDate()));
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, 'Fournil du Pont prend les commandes pour la livraison du');
            self::$assert($email, $formatter->dateLong($sale->getDeliveryDate()));
            self::$assert($email, 'Tu as jusqu\'au');
            self::$assert($email, $formatter->dateTimeLong($sale->getDeadlineAt()));
            self::$assert($email, 'Pain de campagne');
            self::$assert($email, $this->money('5,20').' / pièce');
            self::$assert($email, 'Place du village');
            self::$assert($email, 'http://localhost:8000/fournil-du-pont/vente/'.$sale->getCode());
            self::$assert($email, 'Pour ne plus recevoir ses annonces');
            self::$assert($email, 'http://localhost:8000/fournil-du-pont');
        }

        $logs = $this->logs();
        self::assertCount(2, $logs);
        foreach ($logs as $log) {
            self::assertSame('sale_opened', $log->getType());
            self::assertSame('sale', $log->getRelatedType());
            self::assertSame($sale->getId(), $log->getRelatedId());
            self::assertSame(NotificationStatus::Sent, $log->getStatus());
            self::assertNotNull($log->getCustomer());
        }
    }

    public function testSaleOpenedIsNeverAnnouncedTwiceToTheSameFollower(): void
    {
        $sale = $this->createSale();
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'alice@example.test'])]);

        $this->dispatch(new SaleOpened($sale));
        // A new follower arrives, then the event is dispatched again.
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'late@example.test'])]);
        $this->dispatch(new SaleOpened($sale));

        self::assertQueuedEmailCount(2);
        self::assertSame(['alice@example.test', 'late@example.test'], $this->recipients());

        // Another sale of the same shop is a new announcement.
        $this->dispatch(new SaleOpened($this->createSale($sale->getShop())));
        self::assertQueuedEmailCount(4);
    }

    public function testDeletedAccountsNeverReceiveAnEmailAndTheSkipIsLogged(): void
    {
        $sale = $this->createSale();
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'supprime-42@compte-supprime.invalid'])]);
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'alice@example.test'])]);
        $deletedOrder = $this->createOrder($sale, 1, ['email' => 'supprime-43@compte-supprime.invalid']);

        $this->dispatch(new SaleOpened($sale));
        $this->dispatch(new OrderPlaced($deletedOrder, Actor::Producer));
        // As in SaleLifecycle::cancel(): the sale is cancelled first, then the event is dispatched.
        $sale->cancel();
        $this->dispatch(new SaleCancelled($sale));

        self::assertSame(['alice@example.test'], $this->recipients());

        $statuses = [];
        foreach ($this->logs() as $log) {
            $statuses[] = $log->getType().' '.$log->getRecipient().' '.$log->getStatus()->value;
        }
        sort($statuses);
        self::assertSame([
            'order_placed supprime-43@compte-supprime.invalid skipped',
            'sale_cancelled supprime-43@compte-supprime.invalid skipped',
            'sale_opened alice@example.test sent',
            'sale_opened supprime-42@compte-supprime.invalid skipped',
        ], $statuses);
    }

    public function testSaleClosedSendsTheProductionSummaryToTheProducersOfTheShop(): void
    {
        $sale = $this->createSale(factory: SaleFactory::new()->closed());
        $shop = $sale->getShop();
        UserFactory::createOne(['shop' => $shop, 'email' => 'paul@fournil.test', 'firstName' => 'Paul']);
        UserFactory::createOne(['shop' => $shop, 'email' => 'lea@fournil.test', 'firstName' => 'Léa']);
        UserFactory::createOne(['email' => 'stranger@example.test']);
        $this->createOrder($sale, 2, ['firstName' => 'Camille', 'lastName' => 'Martin', 'phone' => '06 01 02 03 04']);
        $this->createOrder($sale, 3, ['firstName' => 'Noé', 'lastName' => 'Durand']);
        $this->createOrder($sale, 7, ['firstName' => 'Annulé', 'lastName' => 'Fantôme'], OrderFactory::new()->cancelled());
        $formatter = new FrenchFormatter();

        $this->dispatch(new SaleClosed($sale));
        $this->dispatch(new SaleClosed($sale));

        self::assertQueuedEmailCount(2);
        self::assertSame(['lea@fournil.test', 'paul@fournil.test'], $this->recipients());

        foreach ($this->emails() as $email) {
            self::assertEmailSubjectContains($email, 'Commandes closes : livraison du '.$formatter->dateLong($sale->getDeliveryDate()));
            foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
                self::$assert($email, 'Fournée du lundi');
                self::$assert($email, '2 commandes');
                self::$assert($email, $this->money('26,00'));
                self::$assert($email, 'Pain de campagne');
                self::$assert($email, 'Place du village');
                self::$assert($email, 'Camille Martin');
                self::$assert($email, '06 01 02 03 04');
                self::$assert($email, '2 × Pain de campagne');
                self::$assert($email, 'Noé Durand');
                self::$assert($email, '3 × Pain de campagne');
                self::$assert($email, 'http://localhost:8000/pro/ventes/'.$sale->getId());
            }
            self::assertEmailTextBodyContains($email, '- Pain de campagne : 5');
            self::assertEmailHtmlBodyNotContains($email, 'Fantôme');
            self::assertEmailTextBodyNotContains($email, 'Fantôme');
        }

        $logs = $this->logs();
        self::assertCount(2, $logs);
        self::assertSame('sale_closed', $logs[0]->getType());
        self::assertNull($logs[0]->getCustomer());
        self::assertSame($sale->getId(), $logs[0]->getRelatedId());
    }

    public function testSaleClosedWithoutAnyOrderStillInformsTheProducer(): void
    {
        $sale = $this->createSale(factory: SaleFactory::new()->closed());
        UserFactory::createOne(['shop' => $sale->getShop(), 'email' => 'paul@fournil.test']);

        $this->dispatch(new SaleClosed($sale));

        $email = $this->onlyEmail();
        self::assertEmailHtmlBodyContains($email, 'Aucune commande cette fois-ci');
        self::assertEmailTextBodyContains($email, 'Aucune commande cette fois-ci');
    }

    public function testSaleCancelledWarnsCustomersWithAnActiveOrder(): void
    {
        $sale = $this->createSale();
        $active = $this->createOrder($sale, 2, ['email' => 'camille@example.test']);
        $this->createOrder($sale, 1, ['email' => 'cancelled@example.test'], OrderFactory::new()->cancelled());
        $this->createOrder($this->createSale($sale->getShop()), 1, ['email' => 'other-sale@example.test']);
        $sale->cancel();
        $formatter = new FrenchFormatter();

        $this->dispatch(new SaleCancelled($sale));
        $this->dispatch(new SaleCancelled($sale));

        self::assertQueuedEmailCount(1);
        self::assertSame(['camille@example.test'], $this->recipients());

        $email = $this->onlyEmail();
        self::assertEmailSubjectContains($email, 'Fournil du Pont annule la livraison du '.$formatter->dateLong($sale->getDeliveryDate()));
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, 'Fournil du Pont a dû annuler la livraison du');
            self::$assert($email, 'Ta commande '.$active->getReference().' est donc annulée.');
            self::$assert($email, 'Tu n\'as rien à régler');
            self::$assert($email, 'http://localhost:8000/fournil-du-pont');
        }

        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame('sale_cancelled', $logs[0]->getType());
        self::assertSame($active->getCustomer(), $logs[0]->getCustomer());
        self::assertSame('sale', $logs[0]->getRelatedType());
        self::assertSame($sale->getId(), $logs[0]->getRelatedId());
    }
}
