<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Enum\NotificationStatus;
use App\Ordering\Enum\OrderStatus;
use App\Sale\Event\LastMinuteSaleOpened;
use App\Shared\Service\FrenchFormatter;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\ShopFollowerFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * The e-mail of a last-minute sale (SPEC §21, lot F): consenting followers only, those who already
 * ordered left out, sent once, with the one-click unsubscribe of the announcements.
 */
final class LastMinuteNotificationTest extends KernelTestCase
{
    use Factories;
    use NotificationScenario;

    public function testConsentingFollowersWithoutOrderAreToldOnce(): void
    {
        $sale = $this->createSale(factory: SaleFactory::new()->closed());
        $shop = $sale->getShop();
        $bread = $this->breadOffer($sale);
        $bread->setMaxQty(10_000);
        $bread->reserve(6000);
        $bread->putBackOnSale(4000);
        $sale->openLastMinute($sale->getDeliveryDate()->setTime(16, 0), new \DateTimeImmutable('-1 hour'));
        self::getContainer()->get('doctrine')->getManager()->flush();

        ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'alice@example.test', 'firstName' => 'Alice'])]);
        ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'no-consent@example.test']), 'emailConsent' => false]);
        ShopFollowerFactory::createOne(['customer' => CustomerFactory::new(['email' => 'other-shop@example.test'])]);
        $buyer = ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'buyer@example.test'])]);
        OrderFactory::createOne(['sale' => $sale, 'customer' => $buyer->getCustomer()]);
        $cancelled = ShopFollowerFactory::createOne(['shop' => $shop, 'customer' => CustomerFactory::new(['email' => 'cancelled@example.test'])]);
        OrderFactory::createOne(['sale' => $sale, 'customer' => $cancelled->getCustomer(), 'status' => OrderStatus::Cancelled]);

        $this->dispatch(new LastMinuteSaleOpened($sale));
        $this->dispatch(new LastMinuteSaleOpened($sale));

        self::assertSame(['alice@example.test', 'cancelled@example.test'], $this->recipients(), 'Once each, and never to those who already ordered.');

        $email = $this->emails()[0];
        self::assertInstanceOf(Email::class, $email);
        $formatter = new FrenchFormatter();
        self::assertEmailSubjectContains($email, 'Dernière minute chez Fournil du Pont : il reste quelques produits');
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, 'Fournil du Pont remet en vente ce qu\'il lui reste pour la livraison du');
            self::$assert($email, $formatter->dateTimeLong($sale->getDeliveryDate()->setTime(16, 0)));
            self::$assert($email, 'Pain de campagne');
            self::$assert($email, 'reste 4');
            self::$assert($email, 'Place du village');
            self::$assert($email, 'http://localhost:8000/fournil-du-pont/vente/'.$sale->getCode());
            self::$assert($email, 'Pour ne plus recevoir ses annonces');
        }
        self::assertEmailHeaderSame($email, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        self::assertMatchesRegularExpression('#^<http://localhost:8000/e-mails/ne-plus-recevoir/\d+/\d+\?_hash=[^>]+>$#', (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());

        $logs = $this->logs();
        self::assertCount(2, $logs);
        foreach ($logs as $log) {
            self::assertSame('last_minute_sale', $log->getType());
            self::assertSame('sale', $log->getRelatedType());
            self::assertSame($sale->getId(), $log->getRelatedId());
            self::assertSame(NotificationStatus::Sent, $log->getStatus());
        }
    }

    public function testOnlyTheOffersPutBackOnSaleAreListed(): void
    {
        $sale = $this->createSale(factory: SaleFactory::new()->closed());
        $bread = $this->breadOffer($sale);
        SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::new(['shop' => $sale->getShop(), 'name' => 'Brioche'])]);
        $bread->putBackOnSale(3000);
        $sale->openLastMinute($sale->getDeliveryDate()->setTime(16, 0), new \DateTimeImmutable('-1 hour'));
        self::getContainer()->get('doctrine')->getManager()->flush();
        ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'alice@example.test'])]);

        $this->dispatch(new LastMinuteSaleOpened($sale));

        $email = $this->onlyEmail();
        self::assertEmailTextBodyContains($email, 'Pain de campagne (reste 3)');
        self::assertEmailTextBodyNotContains($email, 'Brioche');
        self::assertEmailHtmlBodyNotContains($email, 'Brioche');
    }
}
