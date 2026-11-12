<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Ordering\Event\OrderPlaced;
use App\Sale\Event\SaleClosed;
use App\Sale\Event\SaleOpened;
use App\Shared\Enum\Actor;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\ShopFollowerFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

final class SenderIdentityTest extends KernelTestCase
{
    use Factories;
    use NotificationScenario;

    public function testCustomerEmailsComeFromTheShopAndRepliesGoToTheProducer(): void
    {
        $shop = $this->createShop();
        UserFactory::createOne(['shop' => $shop, 'email' => 'boulanger@example.test']);
        $order = $this->createOrder($this->createSale($shop));

        $this->dispatch(new OrderPlaced($order, Actor::Customer));

        $email = $this->onlyEmail();
        self::assertCount(1, $email->getFrom());
        self::assertSame('bonjour@panier-sauvage.test', $email->getFrom()[0]->getAddress());
        self::assertSame('Fournil du Pont via Panier Sauvage', $email->getFrom()[0]->getName());
        self::assertCount(1, $email->getReplyTo());
        self::assertSame('boulanger@example.test', $email->getReplyTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($email, 'Réponds simplement à cet e-mail');
        // Order e-mails are transactional: no unsubscribe header.
        self::assertFalse($email->getHeaders()->has('List-Unsubscribe'));
    }

    public function testTheProducerSummaryKeepsThePlatformSender(): void
    {
        $shop = $this->createShop();
        $producer = UserFactory::createOne(['shop' => $shop]);
        $sale = $this->createSale($shop);

        $this->dispatch(new SaleClosed($sale));

        $email = $this->onlyEmail();
        self::assertSame($producer->getEmail(), $email->getTo()[0]->getAddress());
        self::assertSame([], $email->getReplyTo());
        self::assertNotSame('Fournil du Pont via Panier Sauvage', $email->getFrom()[0]->getName());
    }

    public function testAnnouncementsCarryAOneClickUnsubscribeLink(): void
    {
        $sale = $this->createSale();
        $follower = ShopFollowerFactory::createOne(['shop' => $sale->getShop(), 'customer' => CustomerFactory::new(['email' => 'alice@example.test'])]);

        $this->dispatch(new SaleOpened($sale));

        $email = $this->onlyEmail();
        $headers = $email->getHeaders();
        self::assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')?->getBodyAsString());

        $header = (string) $headers->get('List-Unsubscribe')?->getBodyAsString();
        $expected = \sprintf('http://localhost:8000/e-mails/ne-plus-recevoir/%d/%d?_hash=', $sale->getShop()->getId(), $follower->getCustomer()->getId());
        self::assertStringStartsWith('<'.$expected, $header);

        $url = substr($header, 1, -1);
        foreach (['assertEmailHtmlBodyContains', 'assertEmailTextBodyContains'] as $assert) {
            self::$assert($email, 'Pour ne plus recevoir ses annonces');
        }
        self::assertEmailTextBodyContains($email, $url);
        self::assertEmailHtmlBodyContains($email, htmlspecialchars($url));
    }
}
