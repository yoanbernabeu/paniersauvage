<?php

declare(strict_types=1);

namespace App\Tests\Ordering;

use App\Accounting\Service\SaleExportBuilder;
use App\Accounting\Service\SalesJournalBuilder;
use App\Catalog\Enum\ProductUnit;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderEvent;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Model\OrderRequest;
use App\Ordering\Service\OrderManager;
use App\Ordering\Service\OrderWeighing;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shop\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Actual weight of the products sold by weight: entered by the producer, it replaces the estimate.
 */
final class OrderWeighingTest extends WebTestCase
{
    use Factories;
    use OrderingFixtures;

    private User $producer;
    private Sale $sale;
    private SaleOffer $tomatoes;
    private SaleOffer $bread;
    private Order $order;

    public function testTheActualWeightReplacesTheEstimateAndIsRecorded(): void
    {
        $this->scenario();
        $weighing = self::getContainer()->get(OrderWeighing::class);
        $line = $this->line('Tomates anciennes');
        self::assertTrue($this->order->isEstimated());
        self::assertSame(675 + 520, $this->order->getTotalCents());

        self::assertTrue($weighing->record($this->order, [$this->lineId($line) => 1620]));

        $order = $this->reload(Order::class, self::idOf($this->order));
        $line = $this->lineOf($order, 'Tomates anciennes');
        self::assertSame(1500, $line->getQuantity(), 'The ordered quantity is kept.');
        self::assertSame(1620, $line->getActualQuantity());
        self::assertSame(1620, $line->getBilledQuantity());
        // 1,62 kg × 4,50 € = 7,29 €.
        self::assertSame(729, $line->getTotalCents());
        self::assertSame(729 + 520, $order->getTotalCents());
        self::assertFalse($order->isEstimated());
        self::assertSame(1500, $this->reload(SaleOffer::class, self::idOf($this->tomatoes))->getReservedQty(), 'Stock stays reserved on the ordered quantity.');

        $event = $this->lastEvent($order);
        self::assertSame(OrderEventType::Weighed, $event->getType());
        self::assertSame(Actor::Producer, $event->getActor());
        self::assertSame([
            'previous_total_cents' => 1195,
            'total_cents' => 1249,
            'changes' => [['label' => 'Tomates anciennes', 'ordered' => 1500, 'from' => null, 'to' => 1620]],
        ], $event->getDetail());

        // Same weight again: nothing to record.
        $order = $this->reload(Order::class, self::idOf($order));
        self::assertFalse($weighing->record($order, [$this->lineId($line) => 1620]));
        self::assertCount(2, $this->reload(Order::class, self::idOf($order))->getEvents());

        // Cleared: back to the estimate.
        $order = $this->reload(Order::class, self::idOf($order));
        self::assertTrue($weighing->record($order, [$this->lineId($line) => null]));
        $order = $this->reload(Order::class, self::idOf($order));
        self::assertTrue($order->isEstimated());
        self::assertSame(1195, $order->getTotalCents());
    }

    public function testOnlyPositiveWeightsOfProductsSoldByWeightOfTheOrder(): void
    {
        $this->scenario();
        $weighing = self::getContainer()->get(OrderWeighing::class);
        $tomatoLine = $this->lineId($this->line('Tomates anciennes'));
        $other = $this->placeOrder($this->sale, $this->createCustomer('paul@example.test'), [self::idOf($this->tomatoes) => 1]);

        foreach ([
            [[$this->lineId($this->line('Pain de campagne')) => 1000], '« Pain de campagne » ne se vend pas au poids.'],
            [[$tomatoLine => 0], 'Indique un poids entre 0,001 et 999 kg pour « Tomates anciennes ».'],
            [[$tomatoLine => 1_000_000], 'Indique un poids entre 0,001 et 999 kg pour « Tomates anciennes ».'],
            [[$this->lineId($this->lineOf($other, 'Tomates anciennes')) => 1000], 'Ce produit ne fait pas partie de la commande.'],
        ] as [$weights, $message]) {
            try {
                $weighing->record($this->order, $weights);
                self::fail($message);
            } catch (OrderNotAllowedException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        self::getContainer()->get(OrderManager::class)->cancelOrder($this->order, Actor::Producer);
        $this->expectExceptionObject(new OrderNotAllowedException('Cette commande est annulée : son poids ne peut plus être saisi.'));
        $weighing->record($this->order, [$tomatoLine => 1000]);
    }

    public function testALineRefusesAWeightForAProductSoldByTheUnit(): void
    {
        $this->scenario();

        $this->expectException(\InvalidArgumentException::class);
        $this->line('Pain de campagne')->setActualQuantity(1000);
    }

    public function testTheProducerWeighsOnTheDeliverySheet(): void
    {
        $client = $this->webScenario();
        $sheetUrl = \sprintf('/pro/ventes/%d/livraison', self::idOf($this->sale));
        $lineId = $this->lineId($this->line('Tomates anciennes'));

        $crawler = $client->request('GET', $sheetUrl);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(\sprintf('[data-order="%s"] [data-weigh-form]', $this->order->getReference()));
        self::assertCount(1, $form);
        self::assertCount(1, $form->filter('input[name^="weights["]'), 'Only the products sold by weight.');
        self::assertSame('1,5', $form->filter('input[name^="weights["]')->attr('placeholder'));
        self::assertStringContainsString("environ 11,95\u{00A0}€", $crawler->filter(\sprintf('[data-order="%s"]', $this->order->getReference()))->text());

        $client->submit($form->selectButton('Enregistrer le poids')->form(), [\sprintf('weights[%d]', $lineId) => '1,62']);

        self::assertResponseRedirects($sheetUrl, 303);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="success"]', 'Poids enregistré pour Léa Martin.');
        $card = $crawler->filter(\sprintf('[data-order="%s"]', $this->order->getReference()))->text();
        self::assertStringContainsString("pesé 1,62\u{00A0}kg", $card);
        self::assertStringContainsString("12,49\u{00A0}€", $card);
        self::assertStringNotContainsString('environ', $card);
        self::assertSame('1,62', $crawler->filter(\sprintf('input[name="weights[%d]"]', $lineId))->attr('value'));
        self::assertSame(1249, $this->reload(Order::class, self::idOf($this->order))->getTotalCents());

        // A typo is refused and changes nothing.
        $client->request('POST', \sprintf('/pro/commandes/%d/poids', self::idOf($this->order)), ['weights' => [$lineId => 'un kilo'], '_csrf_token' => 'csrf-token']);
        self::assertResponseRedirects($sheetUrl, 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', 'Indique un poids en kilos, par exemple 1,25.');
        self::assertSame(1620, $this->lineOf($this->reload(Order::class, self::idOf($this->order)), 'Tomates anciennes')->getActualQuantity());

        // A refused line shows the reason.
        $client->request('POST', \sprintf('/pro/commandes/%d/poids', self::idOf($this->order)), ['weights' => [$this->lineId($this->line('Pain de campagne')) => '1'], '_csrf_token' => 'csrf-token', 'explicit_delivery' => '1']);
        self::assertResponseRedirects($sheetUrl.'?creneau='.$this->deliveryOf($this->sale)->getId(), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-flash="error"]', '« Pain de campagne » ne se vend pas au poids.');
    }

    public function testWeighingRequiresAValidCsrfToken(): void
    {
        $client = $this->webScenario();

        $client->request('POST', \sprintf('/pro/commandes/%d/poids', self::idOf($this->order)), ['weights' => [$this->lineId($this->line('Tomates anciennes')) => '2']]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->lineOf($this->reload(Order::class, self::idOf($this->order)), 'Tomates anciennes')->getActualQuantity());
    }

    public function testAnotherProducerCannotWeighTheOrder(): void
    {
        $client = $this->webScenario();
        $client->loginUser(UserFactory::createOne(), 'producer');

        $client->request('POST', \sprintf('/pro/commandes/%d/poids', self::idOf($this->order)), ['weights' => [$this->lineId($this->line('Tomates anciennes')) => '2'], '_csrf_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->lineOf($this->reload(Order::class, self::idOf($this->order)), 'Tomates anciennes')->getActualQuantity());
    }

    public function testTheProducerWeighsWhenEditingTheOrder(): void
    {
        $client = $this->webScenario();
        $lineId = $this->lineId($this->line('Tomates anciennes'));

        $crawler = $client->request('GET', \sprintf('/pro/ventes/%d/commandes/%d/modifier', self::idOf($this->sale), self::idOf($this->order)));
        self::assertSelectorExists('[data-weights]');
        self::assertSame('1,5', $crawler->filter(\sprintf('input[name="order_form[actualQuantities][%d]"]', $lineId))->attr('placeholder'));

        $client->submitForm('Enregistrer la commande', [\sprintf('order_form[actualQuantities][%d]', $lineId) => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Indique un poids entre 0,001 et 999 kg.', (string) $client->getResponse()->getContent());

        $client->submitForm('Enregistrer la commande', [\sprintf('order_form[actualQuantities][%d]', $lineId) => '1,4']);
        self::assertResponseRedirects();

        $order = $this->reload(Order::class, self::idOf($this->order));
        self::assertSame(1400, $this->lineOf($order, 'Tomates anciennes')->getActualQuantity());
        self::assertSame(630 + 520, $order->getTotalCents());
        self::assertSame(OrderEventType::Weighed, $this->lastEvent($order)->getType());
    }

    public function testTheCustomerSeesTheWeighedAmount(): void
    {
        $client = $this->webScenario();
        self::getContainer()->get(OrderWeighing::class)->record($this->order, [$this->lineId($this->line('Tomates anciennes')) => 1620]);
        $client->loginUser($this->order->getCustomer(), 'main');

        $crawler = $client->request('GET', '/mes-commandes/'.$this->order->getReference());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString("1,5\u{00A0}kg Tomates anciennes · pesé 1,62\u{00A0}kg", $crawler->filter('[data-order-summary]')->text());
        self::assertSame("12,49\u{00A0}€", trim($crawler->filter('[data-order-total]')->text()));
        self::assertSelectorNotExists('[data-weighing-notice]');
    }

    public function testExportsAndAccountingUseTheActualWeight(): void
    {
        $this->scenario();
        $this->sale->close();
        self::getContainer()->get(OrderWeighing::class)->record($this->order, [$this->lineId($this->line('Tomates anciennes')) => 1620]);
        $order = $this->reload(Order::class, self::idOf($this->order));
        $order->markDelivered();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
        $sale = $order->getSale();

        $csv = self::getContainer()->get(SaleExportBuilder::class)->ordersCsv($sale);
        self::assertStringContainsString('"Tomates anciennes";1,5;kg;1,62;4,50;5,5;7,29;12,49', $csv);
        self::assertStringContainsString('"Pain de campagne";1;pièce;;5,20;5,5;5,20;12,49', $csv);

        $sheet = self::getContainer()->get(SaleExportBuilder::class)->productionSheet($sale);
        self::assertSame(1249, $sheet->totalCents);
        self::assertSame(1500, $sheet->lines[1]->quantity, 'What to prepare is what was ordered.');

        $journal = self::getContainer()->get(SalesJournalBuilder::class)->build($sale->getShop(), $sale->getDeliveryDate());
        self::assertSame(1249, $journal->total->grossCents);
        $tomatoes = array_values(array_filter($journal->byProduct, static fn ($row): bool => 'Tomates anciennes' === $row->label))[0];
        self::assertSame(1620, $tomatoes->amounts->quantity);
        self::assertSame(ProductUnit::Kg, $tomatoes->unit);
        self::assertSame(729, $tomatoes->amounts->grossCents);
    }

    private function scenario(): void
    {
        self::bootKernel();
        $this->build();
    }

    private function webScenario(): KernelBrowser
    {
        $client = self::createClient();
        $this->build();
        $client->loginUser($this->producer, 'producer');

        return $client;
    }

    /**
     * An open sale with tomatoes by weight and bread, and Léa's order of 1,5 kg and one loaf.
     */
    private function build(): void
    {
        $this->producer = $this->createProducer();
        $this->sale = $this->createSale($this->shopOf($this->producer));
        $this->tomatoes = $this->createOffer($this->sale, 'Tomates anciennes', 450, null, ['unit' => ProductUnit::Kg, 'quantityStep' => 250, 'minQuantity' => 500]);
        $this->bread = $this->createOffer($this->sale, 'Pain de campagne', 520);
        $this->order = self::getContainer()->get(OrderManager::class)->placeOrder(
            $this->sale,
            $this->createCustomer(),
            new OrderRequest($this->deliveryOf($this->sale), [self::idOf($this->tomatoes) => 1500, self::idOf($this->bread) => 1000]),
            Actor::Producer,
        );
    }

    private function line(string $label): OrderLine
    {
        return $this->lineOf($this->order, $label);
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

    private function lastEvent(Order $order): OrderEvent
    {
        $events = $order->getEvents()->toArray();

        return end($events) ?: throw new \LogicException('No event.');
    }
}
