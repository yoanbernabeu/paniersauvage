<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Catalog\Enum\ProductUnit;
use App\Notification\Service\SaleSummaryBuilder;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Ordering\Service\OrderWeighing;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shop\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Limits and corner cases of selling by weight: largest quantities, producer edits, stale data.
 */
final class WeighedOrderEdgeCasesTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use OrderingFixtures;

    private User $producer;
    private Sale $sale;
    private SaleOffer $bread;
    private SaleOffer $tomatoes;
    private SaleOffer $onions;

    public function testAProducerOrdersUpToTheLargestQuantity(): void
    {
        $client = $this->webScenario();

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/commandes/nouvelle', self::idOf($this->sale)));
        self::assertSame('E-mail (facultatif)', trim($crawler->filter('label[for="order_form_email"]')->text()));
        $client->submitForm('Ajouter la commande', [
            'order_form[delivery]' => (string) $this->deliveryOf($this->sale)->getId(),
            \sprintf('order_form[quantities][%d]', self::idOf($this->tomatoes)) => '99',
            \sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '99',
            'order_form[firstName]' => 'Jeanne',
            'order_form[lastName]' => 'Durand',
            'order_form[phone]' => '06 00 00 00 00',
        ]);

        self::assertResponseRedirects();
        $order = $this->lastOrder();
        self::assertSame(99 * 450 + 99 * 520, $order->getTotalCents());
    }

    public function testTheCustomerFormAsksForAnEmail(): void
    {
        $client = $this->webScenario();

        $crawler = $client->request('GET', \sprintf('/%s/vente/%s', $this->sale->getShop()->getSlug(), $this->sale->getCode()));

        self::assertSame('E-mail', trim($crawler->filter('label[for="order_form_email"]')->text()));
        self::assertNotNull($crawler->filter('input[name="order_form[email]"]')->attr('required'));
    }

    public function testAProducerEditsAWeightFreelyOrEmptiesTheOrder(): void
    {
        $client = $this->webScenario();
        $order = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 1]);
        $url = \sprintf('/pro/ventes/%d/commandes/%d/modifier', self::idOf($this->sale), self::idOf($order));

        $client->request('GET', $url);
        $client->submitForm('Enregistrer la commande', [\sprintf('order_form[quantities][%d]', self::idOf($this->tomatoes)) => '0,3']);
        self::assertResponseRedirects();
        $order = $this->reload(Order::class, self::idOf($order));
        self::assertSame(520 + 135, $order->getTotalCents());

        // The new weighed line can now be weighed in the same form.
        $tomatoLine = $this->lineOf($order, 'Tomates');
        $crawler = $client->request('GET', $url);
        $field = $crawler->filter(\sprintf('input[name="order_form[actualQuantities][%d]"]', $tomatoLine->getId()));
        self::assertSame('decimal', $field->attr('inputmode'));
        self::assertNull($field->attr('required'));
        self::assertSame('Tomates (kg) (facultatif)', trim($crawler->filter(\sprintf('label[for="%s"]', $field->attr('id')))->text()));

        // More than the stock: refused, shown again.
        $client->submitForm('Enregistrer la commande', [\sprintf('order_form[quantities][%d]', self::idOf($this->onions)) => '3']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-stock-alert]');

        // Nothing left: the order is cancelled.
        $client->request('GET', $url);
        $client->submitForm('Enregistrer la commande', [
            \sprintf('order_form[quantities][%d]', self::idOf($this->tomatoes)) => '0',
            \sprintf('order_form[quantities][%d]', self::idOf($this->bread)) => '0',
        ]);
        self::assertResponseRedirects();
        self::assertTrue($this->reload(Order::class, self::idOf($order))->isCancelled());
    }

    public function testALineChecksItsQuantitiesAndKeepsTheTotalUpToDate(): void
    {
        $this->kernelScenario();
        $order = $this->placeOrder($this->sale, $this->createCustomer(), [self::idOf($this->bread) => 1]);

        try {
            new OrderLine($order, $this->bread, 1500);
            self::fail('A fraction of a loaf.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $order->getLines());
        }

        $line = new OrderLine($order, $this->tomatoes, 1000);
        self::assertSame(520 + 450, $order->getTotalCents());
        $line->setActualQuantity(1200);
        self::assertSame(520 + 540, $order->getTotalCents());
        self::assertSame(1200, $line->getBilledQuantity());

        $this->expectException(\InvalidArgumentException::class);
        $line->setActualQuantity(0);
    }

    public function testWeighingSeveralLinesAtTheLimitAndStampsTheOrder(): void
    {
        $this->kernelScenario();
        self::mockTime(new \DateTimeImmutable('2026-11-10 09:00'));
        $order = $this->weighedOrder();
        $tomatoes = $this->lineOf($order, 'Tomates');
        $onions = $this->lineOf($order, 'Oignons');
        $weighing = self::getContainer()->get(OrderWeighing::class);
        $weighing->record($order, [$this->lineId($tomatoes) => 1000]);

        self::mockTime(new \DateTimeImmutable('2026-11-11 18:30'));
        // The first line does not change, the second one does.
        self::assertTrue($weighing->record($order, [$this->lineId($tomatoes) => 1000, $this->lineId($onions) => OrderWeighing::MAX_WEIGHT]));

        $order = $this->reload(Order::class, self::idOf($order));
        self::assertSame(OrderWeighing::MAX_WEIGHT, $this->lineOf($order, 'Oignons')->getActualQuantity());
        self::assertSame('2026-11-11 18:30', $order->getUpdatedAt()->format('Y-m-d H:i'));
    }

    public function testWeighingReadsTheLatestDataUnderALock(): void
    {
        $this->kernelScenario();
        $order = $this->weighedOrder();
        $tomatoes = $this->lineOf($order, 'Tomates');
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $weighing = self::getContainer()->get(OrderWeighing::class);

        // Another request already entered this weight.
        $connection->executeStatement('UPDATE order_line SET actual_quantity = 1100 WHERE id = ?', [$this->lineId($tomatoes)]);
        self::assertFalse($weighing->record($order, [$this->lineId($tomatoes) => 1100]));

        // Another request cancelled the order meanwhile.
        $connection->executeStatement("UPDATE customer_order SET status = 'cancelled' WHERE id = ?", [self::idOf($order)]);
        $this->expectException(OrderNotAllowedException::class);
        $weighing->record($order, [$this->lineId($tomatoes) => 1200]);
    }

    public function testABlankWeightOnTheDeliverySheetClearsIt(): void
    {
        $client = $this->webScenario();
        $order = $this->weighedOrder();
        $tomatoes = $this->lineOf($order, 'Tomates');
        self::getContainer()->get(OrderWeighing::class)->record($order, [$this->lineId($tomatoes) => 1100]);

        $client->request('GET', \sprintf('/pro/ventes/%d/livraison', self::idOf($this->sale)));
        $client->request('POST', \sprintf('/pro/commandes/%d/poids', self::idOf($order)), ['weights' => [$this->lineId($tomatoes) => ' '], '_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorNotExists('[data-flash="error"]');
        self::assertNull($this->lineOf($this->reload(Order::class, self::idOf($order)), 'Tomates')->getActualQuantity());
    }

    public function testTheProductionSummaryAddsUpWeights(): void
    {
        $this->kernelScenario();
        $this->weighedOrder();
        $this->placeOrder($this->sale, $this->createCustomer('paul@example.test'), [self::idOf($this->bread) => 2]);

        $production = self::getContainer()->get(SaleSummaryBuilder::class)->build($this->sale)->production;

        self::assertSame([
            ['label' => 'Oignons', 'unit' => ProductUnit::Kg, 'quantity' => 500],
            ['label' => 'Pain', 'unit' => ProductUnit::Piece, 'quantity' => 2000],
            ['label' => 'Tomates', 'unit' => ProductUnit::Kg, 'quantity' => 1500],
        ], $production);
    }

    public function testTheSaleFormKeepsAWeightAlreadyReserved(): void
    {
        $client = $this->webScenario();
        $this->weighedOrder();

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/modifier', self::idOf($this->sale)));
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = [];
        $crawler->filter('input[name$="[maxQty]"]')->each(static function ($input) use (&$values): void {
            self::assertSame('decimal', $input->attr('inputmode'));
            if ('2' === $input->attr('value')) {
                $values[(string) $input->attr('name')] = '0,25';
            }
        });
        self::assertNotSame([], $values);
        $client->submit($form, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Déjà 0,5 kg réservés', (string) $client->getResponse()->getContent());
    }

    private function webScenario(): KernelBrowser
    {
        $client = self::createClient();
        $this->build();
        $client->loginUser($this->producer, 'producer');

        return $client;
    }

    private function kernelScenario(): void
    {
        self::bootKernel();
        $this->build();
    }

    private function build(): void
    {
        $this->producer = $this->createProducer();
        $this->sale = $this->createSale($this->shopOf($this->producer));
        $this->bread = $this->createOffer($this->sale, 'Pain', 520);
        $this->tomatoes = $this->createOffer($this->sale, 'Tomates', 450, null, ['unit' => ProductUnit::Kg, 'quantityStep' => 250, 'minQuantity' => 500]);
        $this->onions = $this->createOffer($this->sale, 'Oignons', 300, 2, ['unit' => ProductUnit::Kg]);
    }

    private function weighedOrder(): Order
    {
        return self::getContainer()->get(OrderManager::class)->placeOrder(
            $this->sale,
            $this->createCustomer(),
            new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->tomatoes) => 1500, self::idOf($this->onions) => 500]),
            Actor::Producer,
        );
    }

    private function lastOrder(): Order
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $orders = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Order::class)->findBy([], ['id' => 'DESC'], 1);

        return $orders[0] ?? throw new \LogicException('No order.');
    }

    private function lineOf(Order $order, string $label): OrderLine
    {
        foreach ($order->getLines() as $line) {
            if ($line->getLabel() === $label) {
                return $line;
            }
        }

        throw new \LogicException(\sprintf('No line "%s".', $label));
    }

    private function lineId(OrderLine $line): int
    {
        return $line->getId() ?? throw new \LogicException('The line is not persisted.');
    }
}
