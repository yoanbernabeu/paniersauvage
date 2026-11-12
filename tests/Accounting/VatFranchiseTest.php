<?php

declare(strict_types=1);

namespace App\Tests\Accounting;

use App\Accounting\Service\SaleExportBuilder;
use App\Accounting\Service\SalesJournalBuilder;
use App\Accounting\Service\SalesJournalCsvExporter;
use App\Ordering\Entity\Order;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Shared\Enum\Actor;
use App\Shop\Entity\Shop;
use App\Shop\Enum\VatRegime;
use App\Shop\Repository\ShopRepository;
use App\Tests\Factory\OrderFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Ordering\OrderingFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Franchise en base de TVA (art. 293 B du CGI): no VAT on the orders, and the legal mention.
 */
final class VatFranchiseTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    private const string MENTION = 'TVA non applicable, art. 293 B du CGI';

    public function testTheProducerChoosesTheRegimeInTheSettings(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $client->loginUser($producer, 'producer');

        $crawler = $client->request('GET', '/pro/boutique');
        self::assertSame('normal', $crawler->filter('input[name="shop_settings[vatRegime]"]:checked')->attr('value'));
        self::assertSelectorTextContains('main', 'Franchise en base de TVA (art. 293 B du CGI) : pas de TVA');
        self::assertSelectorTextContains('main', 'Régime de TVA');
        self::assertSelectorTextContains('main', 'portent la mention « '.self::MENTION.' »');

        $client->submit($crawler->selectButton('Enregistrer')->form(['shop_settings[vatRegime]' => 'franchise']));

        self::assertResponseRedirects('/pro/boutique');
        $shop = self::getContainer()->get(ShopRepository::class)->find((int) $producer->getShop()?->getId());
        self::assertInstanceOf(Shop::class, $shop);
        self::assertSame(VatRegime::Franchise, $shop->getVatRegime());
        self::assertTrue($shop->isVatExempt());
    }

    public function testOrdersUnderTheFranchiseAreFrozenWithoutVatAndCarryTheMention(): void
    {
        $client = self::createClient();
        $shop = $this->shopOf($this->createProducer())->setVatRegime(VatRegime::Franchise);
        $sale = $this->createSale($shop);
        $honey = $this->createOffer($sale, 'Miel de lavande', 1200, null, ['vatRateBp' => 550]);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($honey) => 2]);

        self::assertTrue($order->isVatExempt());
        self::assertSame(0, ($order->getLines()->first() ?: throw new \LogicException())->getVatRateBp());
        self::assertSame(2400, $order->getTotalCents(), 'The price stays the same: no VAT is added.');

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, self::MENTION);
        self::assertEmailTextBodyContains($email, self::MENTION);

        // Leaving the franchise only changes the new orders.
        $shop->setVatRegime(VatRegime::Normal);
        $later = $this->placeOrder($sale, $this->createCustomer('paul@example.test'), [self::idOf($honey) => 1]);
        self::assertFalse($later->isVatExempt());
        self::assertSame(550, ($later->getLines()->first() ?: throw new \LogicException())->getVatRateBp());
        $edited = self::getContainer()->get(OrderManager::class)->updateOrder($order, new OrderRequest($this->deliveryOf($sale), [self::idOf($honey) => 3000]), Actor::Producer);
        self::assertTrue($edited->isVatExempt(), 'The regime is frozen on the order.');
        self::assertSame(0, ($edited->getLines()->first() ?: throw new \LogicException())->getVatRateBp());

        $client->loginUser($order->getCustomer(), 'main');
        $client->request('GET', '/mes-commandes/'.$order->getReference());
        self::assertSelectorTextContains('[data-vat-mention]', self::MENTION);
        $client->loginUser($later->getCustomer(), 'main');
        $client->request('GET', '/mes-commandes/'.$later->getReference());
        self::assertSelectorNotExists('[data-vat-mention]');

        $csv = self::getContainer()->get(SaleExportBuilder::class)->ordersCsv($sale);
        self::assertStringContainsString('"Miel de lavande";3;pièce;;12,00;0;36,00;36,00;"'.self::MENTION.'"', $csv);
        self::assertStringContainsString('"Miel de lavande";1;pièce;;12,00;5,5;12,00;12,00;', $csv);
    }

    public function testTheJournalKeepsFranchiseSalesApart(): void
    {
        $client = self::createClient();
        $producer = UserFactory::createOne();
        $shop = $this->shopOf($producer);
        $sale = SaleFactory::new()->done()->create(['shop' => $shop, 'deliveryDate' => new \DateTimeImmutable('2026-09-14'), 'opensAt' => new \DateTimeImmutable('2026-09-01'), 'deadlineAt' => new \DateTimeImmutable('2026-09-11')]);
        $honey = SaleOfferFactory::createOne(['sale' => $sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Miel', 'priceCents' => 1000, 'vatRateBp' => 550])]);
        // One order before the switch to the franchise, one after.
        OrderLineFactory::createOne(['order' => OrderFactory::new()->delivered()->create(['sale' => $sale]), 'saleOffer' => $honey, 'quantity' => 1000]);
        $shop->setVatRegime(VatRegime::Franchise);
        OrderLineFactory::createOne(['order' => OrderFactory::new()->delivered()->create(['sale' => $sale]), 'saleOffer' => $honey, 'quantity' => 2000]);

        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($shop, new \DateTimeImmutable('2026-09-01'));

        self::assertTrue($journal->isVatExempt());
        self::assertCount(2, $journal->byVatRate);
        [$normal, $franchise] = $journal->byVatRate;
        self::assertFalse($normal->vatExempt);
        self::assertSame([550, 1000, 948, 52], [$normal->vatRateBp, $normal->amounts->grossCents, $normal->amounts->netCents, $normal->amounts->vatCents]);
        self::assertTrue($franchise->vatExempt);
        self::assertSame([0, 2000, 2000, 0], [$franchise->vatRateBp, $franchise->amounts->grossCents, $franchise->amounts->netCents, $franchise->amounts->vatCents]);
        self::assertCount(2, $journal->byProduct);
        self::assertSame([false, true], [$journal->byProduct[0]->vatExempt, $journal->byProduct[1]->vatExempt], 'Same product: the taxed row first.');
        self::assertCount(2, $journal->details);
        self::assertSame(52, $journal->total->vatCents);

        $csv = self::getContainer()->get(SalesJournalCsvExporter::class)->export($journal);
        self::assertStringContainsString(';Miel;0;2;pièce;20,00;20,00;0,00;"'.self::MENTION.'"', $csv);
        self::assertStringContainsString(';Miel;5,5;1;pièce;10,00;9,48;0,52;'."\r\n", $csv);
        self::assertStringContainsString('Total;;;;"Total franchise en base de TVA";0;;;20,00;20,00;0,00;"'.self::MENTION.'"', $csv);

        $client->loginUser($producer, 'producer');
        $client->request('GET', '/pro/comptabilite?mois=2026-09');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-vat-mention]', self::MENTION);
        self::assertSelectorTextContains('[data-journal="vat"]', 'Franchise en base');
    }

    public function testANormalShopHasNoMention(): void
    {
        self::bootKernel();
        $shop = $this->shopOf($this->createProducer());
        $sale = $this->createSale($shop);
        $order = $this->placeOrder($sale, $this->createCustomer(), [self::idOf($this->createOffer($sale, 'Pain', 520)) => 1]);
        $order->markDelivered();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($shop, $sale->getDeliveryDate());
        self::assertFalse($journal->isEmpty());

        self::assertFalse($shop->isVatExempt());
        self::assertFalse($order->isVatExempt());
        self::assertFalse(self::getContainer()->get(SalesJournalBuilder::class)->build($shop, $sale->getDeliveryDate())->isVatExempt());
        self::assertSame(VatRegime::Normal, new Shop('Fournil', 'fournil')->getVatRegime());
        self::assertInstanceOf(Order::class, $order);
    }
}
