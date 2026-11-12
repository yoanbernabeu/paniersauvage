<?php

declare(strict_types=1);

namespace App\Tests\Sale;

use App\Ordering\Entity\DeliverySheetAction;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderEvent;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Enum\SheetActionOutcome;
use App\Ordering\Enum\SheetActionType;
use App\Ordering\Exception\SheetActionRejected;
use App\Ordering\Model\SheetAction;
use App\Ordering\Model\SheetActionPayload;
use App\Ordering\Service\DeliverySheetSync;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shop\Entity\User;
use App\Tests\Factory\CustomerFactory;
use App\Tests\Factory\OrderLineFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\SaleDeliveryFactory;
use App\Tests\Factory\SaleFactory;
use App\Tests\Factory\SaleOfferFactory;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Zenstruck\Foundry\Test\Factories;

/**
 * The delivery sheet's offline queue, seen from the server (SPEC §20, lot F): every action is
 * idempotent (its identifier), refused when the order changed in the meantime (its expected state),
 * and answered with the order as the server now sees it.
 */
final class DeliverySheetSyncTest extends WebTestCase
{
    use Factories;
    use SaleScenario;

    private const string FIRST = '0b9f6a52-6b7e-4c39-9d43-5b2a1f0e8c11';
    private const string SECOND = '7d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a';

    private User $producer;
    private Sale $sale;
    private SaleOffer $bread;
    private SaleOffer $tomatoes;
    private Order $jeanne;
    private Order $paul;
    private OrderLine $tomatoLine;

    public function testAMarkIsAppliedOnceEvenWhenReplayed(): void
    {
        $client = $this->createClientWithTour();

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertResponseIsSuccessful();
        self::assertSame('applied', $answer['outcome']);
        self::assertFalse($answer['replayed']);
        self::assertNull($answer['message']);
        self::assertSame(['id' => $this->jeanne->getId(), 'status' => 'delivered', 'total' => "16,75\u{00A0}€", 'estimated' => true, 'weights' => [$this->tomatoLine->getId() => null]], $answer['order']);
        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(OrderStatus::Delivered, $order->getStatus());
        self::assertCount(1, $order->getEvents());

        // The answer was lost: the browser sends the same action again.
        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertResponseIsSuccessful();
        self::assertSame('applied', $answer['outcome']);
        self::assertTrue($answer['replayed']);
        self::assertCount(1, self::reload(Order::class, $this->jeanne->getId())->getEvents(), 'A replay never adds a second history entry.');

        $action = $this->action(self::FIRST);
        self::assertSame(SheetActionType::Mark, $action->getType());
        self::assertSame(SheetActionOutcome::Applied, $action->getOutcome());
        self::assertSame($this->jeanne->getId(), $action->getOrder()->getId());
        self::assertNull($action->getMessage());
    }

    public function testAReplayDoesNotUndoALaterAction(): void
    {
        $client = $this->createClientWithTour();

        // Delivered, then unticked; then the first action comes again (lost answer).
        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);
        $this->post($client, $this->jeanne, ['id' => self::SECOND, 'type' => 'mark', 'status' => 'confirmed', 'expected' => 'delivered']);
        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertTrue($answer['replayed']);
        self::assertSame('confirmed', $this->stateOf($answer)['status']);
        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(OrderStatus::Confirmed, $order->getStatus());
        self::assertSame([OrderEventType::Delivered, OrderEventType::Reconfirmed], array_map(static fn (OrderEvent $event): OrderEventType => $event->getType(), $order->getEvents()->toArray()));
    }

    public function testAnOrderCancelledInTheMeantimeIsAConflict(): void
    {
        $client = $this->createClientWithTour();
        $this->cancel($this->jeanne);

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertResponseIsSuccessful();
        self::assertSame('conflict', $answer['outcome']);
        self::assertSame('La commande de Jeanne Martin a été annulée entre-temps : ne la remets pas.', $answer['message']);
        self::assertSame('cancelled', $this->stateOf($answer)['status']);
        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertCount(0, $order->getEvents());

        // Replayed: the same conflict, recorded once.
        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);
        self::assertSame('conflict', $answer['outcome']);
        self::assertTrue($answer['replayed']);
        self::assertSame('La commande de Jeanne Martin a été annulée entre-temps : ne la remets pas.', $answer['message']);
    }

    public function testAnOrderChangedElsewhereIsAConflict(): void
    {
        $client = $this->createClientWithTour();

        // Paul was ticked on another phone; this one still saw him "to deliver".
        $answer = $this->post($client, $this->paul, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'not_delivered', 'expected' => 'confirmed']);

        self::assertSame('conflict', $answer['outcome']);
        self::assertSame('La commande de Paul Durand a changé entre-temps (livrée) : vérifie-la avant de la cocher.', $answer['message']);
        self::assertSame(OrderStatus::Delivered, self::reload(Order::class, $this->paul->getId())->getStatus());
        self::assertCount(0, self::reload(Order::class, $this->paul->getId())->getEvents());
    }

    public function testAnActionAlreadyDoneElsewhereChangesNothing(): void
    {
        $client = $this->createClientWithTour();

        $answer = $this->post($client, $this->paul, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertSame('unchanged', $answer['outcome']);
        self::assertNull($answer['message']);
        self::assertCount(0, self::reload(Order::class, $this->paul->getId())->getEvents());
        self::assertSame(SheetActionOutcome::Unchanged, $this->action(self::FIRST)->getOutcome());
    }

    public function testOrdersOfAnOpenSaleCannotBeTickedYet(): void
    {
        $client = $this->createClientWithTour(open: true);

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertSame('conflict', $answer['outcome']);
        self::assertSame('Les commandes de cette vente ne sont pas encore closes : la commande de Jeanne Martin n\'est pas cochée.', $answer['message']);
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
    }

    public function testAWeightIsRecordedOnceAndTheAmountFollows(): void
    {
        $client = $this->createClientWithTour();
        $lineId = (string) $this->tomatoLine->getId();

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'weigh', 'weights' => [$lineId => 1250], 'expectedWeights' => [$lineId => null]]);

        self::assertSame('applied', $answer['outcome']);
        // 2 loaves at 5 € + 1.25 kg at 4.50 € (5.625 € → 5.63 €).
        self::assertSame("15,63\u{00A0}€", $this->stateOf($answer)['total']);
        self::assertFalse($this->stateOf($answer)['estimated']);
        self::assertSame([$lineId => 1250], $this->stateOf($answer)['weights']);
        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'weigh', 'weights' => [$lineId => 1250], 'expectedWeights' => [$lineId => null]]);

        $order = self::reload(Order::class, $this->jeanne->getId());
        self::assertSame(1563, $order->getTotalCents());
        self::assertCount(1, $order->getEvents());
        self::assertSame([OrderEventType::Weighed], array_map(static fn (OrderEvent $event): OrderEventType => $event->getType(), $order->getEvents()->toArray()));

        // The same weight again (another action): nothing to save.
        $answer = $this->post($client, $this->jeanne, ['id' => self::SECOND, 'type' => 'weigh', 'weights' => [$lineId => 1250], 'expectedWeights' => [$lineId => null]]);
        self::assertSame('unchanged', $answer['outcome']);
        self::assertCount(1, self::reload(Order::class, $this->jeanne->getId())->getEvents());
    }

    public function testAWeightChangedElsewhereIsAConflict(): void
    {
        $client = $this->createClientWithTour();
        $lineId = (string) $this->tomatoLine->getId();
        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'weigh', 'weights' => [$lineId => 1250], 'expectedWeights' => [$lineId => null]]);

        // This phone still saw no weight, and typed another one.
        $answer = $this->post($client, $this->jeanne, ['id' => self::SECOND, 'type' => 'weigh', 'weights' => [$lineId => 1400], 'expectedWeights' => [$lineId => null]]);

        self::assertSame('conflict', $answer['outcome']);
        self::assertSame("Le poids de « Tomates anciennes » (commande de Jeanne Martin) a changé entre-temps (1,25\u{00A0}kg) : vérifie-le.", $answer['message']);
        self::assertSame(1250, self::reload(OrderLine::class, $this->tomatoLine->getId())->getActualQuantity());

        // Cleared elsewhere, then seen with a weight: the message says so.
        $this->post($client, $this->jeanne, ['id' => '11111111-2222-4333-8444-555555555555', 'type' => 'weigh', 'weights' => [$lineId => null], 'expectedWeights' => [$lineId => 1250]]);
        $answer = $this->post($client, $this->jeanne, ['id' => '11111111-2222-4333-8444-666666666666', 'type' => 'weigh', 'weights' => [$lineId => 900], 'expectedWeights' => [$lineId => 1250]]);
        self::assertSame('Le poids de « Tomates anciennes » (commande de Jeanne Martin) a changé entre-temps (effacé) : vérifie-le.', $answer['message']);
    }

    public function testAWeightWithoutExpectationIsAppliedAndCancelledOrdersRefuseIt(): void
    {
        $client = $this->createClientWithTour();
        $lineId = (string) $this->tomatoLine->getId();

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'weigh', 'weights' => [$lineId => 800]]);
        self::assertSame('applied', $answer['outcome']);

        $this->cancel($this->jeanne);
        $answer = $this->post($client, $this->jeanne, ['id' => self::SECOND, 'type' => 'weigh', 'weights' => [$lineId => 900], 'expectedWeights' => [$lineId => 800]]);
        self::assertSame('conflict', $answer['outcome']);
        self::assertSame('La commande de Jeanne Martin a été annulée entre-temps : son poids n\'est pas enregistré.', $answer['message']);
        self::assertSame(800, self::reload(OrderLine::class, $this->tomatoLine->getId())->getActualQuantity());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function impossibleActions(): iterable
    {
        yield 'mark without expected status' => [['type' => 'mark', 'status' => 'delivered'], 'Cette action est incomplète.'];
        yield 'mark to cancelled' => [['type' => 'mark', 'status' => 'cancelled', 'expected' => 'confirmed'], ''];
        yield 'weigh without weight' => [['type' => 'weigh', 'weights' => []], 'Cette action est incomplète.'];
        yield 'weight as a string' => [['type' => 'weigh', 'weights' => ['LINE' => '1,5']], 'Cette action est incomplète.'];
        yield 'weight of a line of another order' => [['type' => 'weigh', 'weights' => ['999999' => 1000]], 'Ce produit ne fait pas partie de la commande.'];
        yield 'weight of a product sold by the unit' => [['type' => 'weigh', 'weights' => ['BREAD' => 1000]], '« Pain de campagne » ne se vend pas au poids.'];
        yield 'weight out of range' => [['type' => 'weigh', 'weights' => ['LINE' => 1_000_000]], 'Indique un poids entre 0,001 et 999 kg pour « Tomates anciennes ».'];
        yield 'weight keyed by a name' => [['type' => 'weigh', 'weights' => ['tomates' => 1000]], 'Cette action est incomplète.'];
        yield 'zero weight' => [['type' => 'weigh', 'weights' => ['LINE' => 0]], 'Indique un poids entre 0,001 et 999 kg pour « Tomates anciennes ».'];
    }

    /**
     * @param array<string, mixed> $action
     */
    #[DataProvider('impossibleActions')]
    public function testAnImpossibleActionIsRejected(array $action, string $message): void
    {
        $client = $this->createClientWithTour();
        if (isset($action['weights']) && \is_array($action['weights'])) {
            $breadLine = $this->jeanne->getLines()->findFirst(static fn (int $key, OrderLine $line): bool => !$line->isWeighed());
            $weights = [];
            foreach ($action['weights'] as $key => $weight) {
                $weights[match ($key) {
                    'LINE' => (string) $this->tomatoLine->getId(),
                    'BREAD' => (string) $breadLine?->getId(),
                    default => (string) $key,
                }] = $weight;
            }
            $action['weights'] = $weights;
        }

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST] + $action);

        self::assertResponseStatusCodeSame(422);
        if ('' !== $message) {
            self::assertSame(['outcome' => 'rejected', 'message' => $message], $answer);
        }
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
        self::assertCount(0, self::reload(Order::class, $this->jeanne->getId())->getEvents());
        self::assertNull(self::entityManager()->find(DeliverySheetAction::class, self::FIRST), 'A rejected action is not recorded.');
    }

    public function testSeveralWeightsAtOnceAndTheHeaviestOne(): void
    {
        $client = $this->createClientWithTour();
        $zucchini = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::new()->byWeight()->create(['shop' => self::shopOf($this->producer), 'name' => 'Courgettes', 'priceCents' => 300])]);
        $zucchiniLine = OrderLineFactory::createOne(['order' => $this->jeanne, 'saleOffer' => $zucchini, 'quantity' => 1000]);
        $tomatoId = (string) $this->tomatoLine->getId();
        $zucchiniId = (string) $zucchiniLine->getId();

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'weigh', 'weights' => [$tomatoId => 1250, $zucchiniId => 999_000], 'expectedWeights' => [$tomatoId => null, $zucchiniId => null]]);

        self::assertSame('applied', $answer['outcome']);
        self::assertSame([(int) $tomatoId => 1250, (int) $zucchiniId => 999_000], self::stateOf($answer)['weights']);

        // The tomatoes are unchanged, the courgettes are weighed again: only they change.
        $answer = $this->post($client, $this->jeanne, ['id' => self::SECOND, 'type' => 'weigh', 'weights' => [$tomatoId => 1250, $zucchiniId => 1100], 'expectedWeights' => [$tomatoId => 1250, $zucchiniId => 999_000]]);

        self::assertSame('applied', $answer['outcome']);
        self::assertSame(1250, self::reload(OrderLine::class, $this->tomatoLine->getId())->getActualQuantity());
        self::assertSame(1100, self::reload(OrderLine::class, $zucchiniLine->getId())->getActualQuantity());
    }

    public function testTheSyncReadsTheLatestStateUnderALock(): void
    {
        $this->createClientWithTour();
        $sync = self::getContainer()->get(DeliverySheetSync::class);
        $entityManager = self::entityManager();
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $this->jeanne->getId()) ?? throw new \LogicException('No order.');
        $connection = $entityManager->getConnection();
        $level = $connection->getTransactionNestingLevel();

        // Cancelled by another request since the order was loaded.
        $connection->executeStatement('UPDATE customer_order SET status = ? WHERE id = ?', ['cancelled', $order->getId()]);
        $result = $sync->apply($order, new SheetAction(self::FIRST, SheetActionType::Mark, OrderStatus::Delivered, OrderStatus::Confirmed));
        self::assertSame(SheetActionOutcome::Conflict, $result->outcome);
        self::assertSame($level, $connection->getTransactionNestingLevel(), 'The transaction is committed.');

        $replayed = $sync->apply($order, new SheetAction(self::FIRST, SheetActionType::Mark, OrderStatus::Delivered, OrderStatus::Confirmed));
        self::assertTrue($replayed->replayed);
        self::assertSame($level, $connection->getTransactionNestingLevel(), 'The transaction of a replay is committed too.');
    }

    public function testTheSyncReadsTheLatestSaleAndWeights(): void
    {
        $this->createClientWithTour();
        $sync = self::getContainer()->get(DeliverySheetSync::class);
        $entityManager = self::entityManager();
        $entityManager->clear();
        $order = $entityManager->find(Order::class, $this->jeanne->getId()) ?? throw new \LogicException('No order.');
        self::assertTrue($order->getSale()->isClosed());
        foreach ($order->getLines() as $line) {
            self::assertNull($line->getActualQuantity());
        }
        $connection = $entityManager->getConnection();

        // Weighed on another phone since the order was loaded.
        $connection->executeStatement('UPDATE order_line SET actual_quantity = 700 WHERE id = ?', [$this->tomatoLine->getId()]);
        $result = $sync->apply($order, new SheetAction(self::FIRST, SheetActionType::Weigh, weights: [(int) $this->tomatoLine->getId() => 900], expectedWeights: [(int) $this->tomatoLine->getId() => null]));
        self::assertSame(SheetActionOutcome::Conflict, $result->outcome);

        // The sale was cancelled by another request.
        $connection->executeStatement('UPDATE sale SET status = ? WHERE id = ?', ['cancelled', $this->sale->getId()]);
        $result = $sync->apply($order, new SheetAction(self::SECOND, SheetActionType::Mark, OrderStatus::Delivered, OrderStatus::Confirmed));
        self::assertSame(SheetActionOutcome::Conflict, $result->outcome);
        self::assertSame('La commande de Jeanne Martin a été annulée entre-temps : ne la remets pas.', $result->message);
    }

    public function testTheActionTableKeepsItsShape(): void
    {
        $metadata = self::entityManager()->getClassMetadata(DeliverySheetAction::class);

        self::assertSame('delivery_sheet_action', $metadata->getTableName());
        self::assertSame(36, $metadata->getFieldMapping('id')->length);
        self::assertSame(10, $metadata->getFieldMapping('type')->length);
        self::assertSame(10, $metadata->getFieldMapping('outcome')->length);
        self::assertSame(255, $metadata->getFieldMapping('message')->length);
        self::assertTrue($metadata->getFieldMapping('message')->nullable);
        $order = $metadata->getAssociationMapping('order');
        self::assertInstanceOf(ManyToOneAssociationMapping::class, $order);
        self::assertFalse($order->joinColumns[0]->nullable);
        self::assertSame('CASCADE', $order->joinColumns[0]->onDelete);
    }

    public function testAMalformedIdentifierIsRejected(): void
    {
        $client = $this->createClientWithTour();

        $this->post($client, $this->jeanne, ['id' => 'not-a-uuid', 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);
        self::assertResponseStatusCodeSame(422);

        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'pay', 'status' => 'delivered', 'expected' => 'confirmed']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
    }

    public function testAnIdentifierCannotServeAnotherOrder(): void
    {
        $client = $this->createClientWithTour();
        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        $answer = $this->post($client, $this->paul, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'confirmed', 'expected' => 'delivered']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Cette action a déjà servi pour une autre commande.', $answer['message']);
        self::assertSame(OrderStatus::Delivered, self::reload(Order::class, $this->paul->getId())->getStatus());

        // Nor another kind of action on the same order.
        $lineId = (string) $this->tomatoLine->getId();
        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'weigh', 'weights' => [$lineId => 1000]]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::reload(OrderLine::class, $this->tomatoLine->getId())->getActualQuantity());
    }

    public function testTheIdentifierIsCaseInsensitive(): void
    {
        $client = $this->createClientWithTour();
        $this->post($client, $this->jeanne, ['id' => strtoupper(self::FIRST), 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        $answer = $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertTrue($answer['replayed']);
    }

    public function testAnotherProducerGetsNotFound(): void
    {
        $client = $this->createClientWithTour();
        $client->loginUser(self::subscribedProducer(), 'producer');

        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
        self::assertNull(self::entityManager()->find(DeliverySheetAction::class, self::FIRST));
    }

    public function testTheCsrfHeaderIsRequired(): void
    {
        $client = $this->createClientWithTour();

        $client->request('POST', '/pro/commandes/'.$this->jeanne->getId().'/action', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: json_encode(['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
    }

    public function testTheDoubleSubmittedTokenOfTheBrowserIsAccepted(): void
    {
        $client = $this->createClientWithTour();
        $token = str_repeat('Ab3', 10);
        $client->getCookieJar()->set(new Cookie('csrf-token_'.$token, 'csrf-token'));

        // What delivery_sheet_controller.js sends: the token in the header and in a cookie, plus the origin.
        $client->request('POST', '/pro/commandes/'.$this->jeanne->getId().'/action', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_CSRF_TOKEN' => $token,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], content: json_encode(['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame(OrderStatus::Delivered, self::reload(Order::class, $this->jeanne->getId())->getStatus());
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $client = $this->createClientWithTour();
        $client->restart();

        $this->post($client, $this->jeanne, ['id' => self::FIRST, 'type' => 'mark', 'status' => 'delivered', 'expected' => 'confirmed']);

        self::assertResponseRedirects();
        self::assertSame(OrderStatus::Confirmed, self::reload(Order::class, $this->jeanne->getId())->getStatus());
    }

    public function testTheSheetCarriesWhatTheQueueNeeds(): void
    {
        $client = $this->createClientWithTour();

        $crawler = $client->request('GET', '/pro/ventes/'.$this->sale->getId().'/livraison');

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('[data-controller="delivery-sheet"]');
        self::assertSame('panier-sauvage:feuille-de-livraison:'.$this->producer->getId(), $root->attr('data-delivery-sheet-queue-key-value'));
        self::assertSame('csrf-token', $root->attr('data-delivery-sheet-csrf-value'));
        self::assertSame('/pro/feuille-hors-ligne.js', $root->attr('data-delivery-sheet-worker-value'));
        self::assertSame('/pro/', $root->attr('data-delivery-sheet-scope-value'));
        // The saving state and the conflicts only exist with JavaScript.
        self::assertNotNull($crawler->filter('[data-delivery-sheet-target="syncBar"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('[data-delivery-sheet-target="conflicts"]')->attr('hidden'));

        $card = $crawler->filter('[data-order="'.$this->jeanne->getReference().'"]');
        self::assertSame('/pro/commandes/'.$this->jeanne->getId().'/action', $card->attr('data-action-url'));
        self::assertSame((string) $this->jeanne->getId(), $card->attr('data-order-id'));
        self::assertSame('Jeanne Martin', $card->attr('data-order-name'));
        self::assertSame('confirmed', $card->attr('data-order-status'));
        self::assertSame('0', $card->attr('data-position'));
        // Every state of the buttons is there; only the current one is shown.
        self::assertNotNull($card->filter('button[role="checkbox"][value="confirmed"]')->attr('hidden'));
        self::assertNull($card->filter('button[role="checkbox"][value="delivered"]')->attr('hidden'));
        self::assertNull($card->filter('button[value="not_delivered"]')->attr('hidden'));
        self::assertNotNull($card->filter('button:not([role])[value="confirmed"]')->attr('hidden'));
        self::assertNotNull($card->filter('[data-when="not_delivered"]')->first()->attr('hidden'));
        // Lines: what the amount and the truck load are computed from.
        $tomatoes = $card->filter('[data-line="'.$this->tomatoLine->getId().'"]');
        self::assertSame('1500', $tomatoes->attr('data-quantity'));
        self::assertSame('450', $tomatoes->attr('data-unit-price'));
        self::assertSame('1', $tomatoes->attr('data-weighed'));
        self::assertSame('', $tomatoes->attr('data-actual'));
        self::assertSame((string) $this->tomatoes->getId(), $tomatoes->attr('data-offer'));
        self::assertSame("environ 16,75\u{00A0}€", $card->filter('[data-order-total]')->text());
        self::assertCount(1, $card->filter('[data-weigh-form] [data-line-input="'.$this->tomatoLine->getId().'"]'));

        $delivered = $crawler->filter('[data-order="'.$this->paul->getReference().'"]');
        self::assertNull($delivered->filter('button[role="checkbox"][value="confirmed"]')->attr('hidden'));
        self::assertNotNull($delivered->filter('button[role="checkbox"][value="delivered"]')->attr('hidden'));
        self::assertSame('1', $delivered->attr('data-position'));

        $truck = $crawler->filter('[data-delivery-sheet-target="truckItem"]');
        self::assertSame((string) $this->bread->getId(), $truck->eq(0)->attr('data-offer'));
        self::assertSame('3000', $truck->eq(0)->attr('data-total'));
        self::assertSame('0', $truck->eq(0)->attr('data-weighed'));
        self::assertSame('1', $truck->eq(1)->attr('data-weighed'));
        self::assertSelectorTextContains('[data-offline-help]', 'Pas de réseau sur la tournée ?');
        // Both lists exist, even empty, so that a card can move from one to the other.
        self::assertSelectorCount(1, '[data-list="todo"]');
        self::assertSelectorCount(1, '[data-list="done"]');
    }

    public function testTheServiceWorkerIsServedToTheProducer(): void
    {
        $client = $this->createClientWithTour();

        $client->request('GET', '/pro/feuille-hors-ligne.js');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/javascript; charset=UTF-8');
        self::assertStringContainsString('no-cache', (string) $client->getResponse()->headers->get('Cache-Control'));
        $script = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("const LOGOUT = '\\/pro\\/deconnexion';", $script);
        self::assertStringContainsString('const SHEET = /^\/pro\/ventes\/\d+\/livraison$/;', $script);

        $client->restart();
        $client->request('GET', '/pro/feuille-hors-ligne.js');
        self::assertResponseRedirects();
    }

    public function testThePayloadNeedsAWeightOrAStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SheetActionPayload(self::FIRST, 'mark', 'delivered')->toAction();
    }

    public function testTheSyncServiceRefusesToCancelAnOrder(): void
    {
        $this->createClientWithTour();
        $sync = self::getContainer()->get(DeliverySheetSync::class);
        $order = self::reload(Order::class, $this->jeanne->getId());

        try {
            $sync->apply($order, new SheetAction(self::FIRST, SheetActionType::Mark, OrderStatus::Cancelled, OrderStatus::Confirmed));
            self::fail('A cancellation is not a delivery action.');
        } catch (SheetActionRejected $exception) {
            self::assertSame('Une commande ne s\'annule pas depuis la feuille de livraison.', $exception->getMessage());
        }

        try {
            $sync->apply($order, new SheetAction(self::SECOND, SheetActionType::Mark));
            self::fail('A mark needs a status.');
        } catch (SheetActionRejected $exception) {
            self::assertSame('Indique le nouveau statut de la commande.', $exception->getMessage());
        }
        self::assertFalse(self::entityManager()->getConnection()->isTransactionActive());
    }

    /**
     * @param array<string, mixed> $action
     *
     * @return array<mixed>
     */
    private function post(KernelBrowser $client, Order $order, array $action): array
    {
        $client->request('POST', '/pro/commandes/'.$order->getId().'/action', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], content: json_encode($action, \JSON_THROW_ON_ERROR));

        $content = (string) $client->getResponse()->getContent();
        if (!json_validate($content)) {
            return [];
        }
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $answer
     *
     * @return array<mixed> the order as the server sees it
     */
    private function stateOf(array $answer): array
    {
        self::assertIsArray($answer['order'] ?? null);

        return $answer['order'];
    }

    private function action(string $id): DeliverySheetAction
    {
        $entityManager = self::entityManager();
        $entityManager->clear();

        return $entityManager->find(DeliverySheetAction::class, $id) ?? throw new \LogicException('No action '.$id.'.');
    }

    private function cancel(Order $order): void
    {
        $entityManager = self::entityManager();
        $order = $entityManager->find(Order::class, $order->getId()) ?? throw new \LogicException('No order.');
        $order->cancel();
        $entityManager->flush();
    }

    /**
     * A closed sale: Jeanne (2 loaves and 1.5 kg of tomatoes, to deliver), Paul (1 loaf, delivered).
     */
    private function createClientWithTour(bool $open = false): KernelBrowser
    {
        $client = self::createClient();
        $this->producer = self::subscribedProducer();
        $shop = self::shopOf($this->producer);

        $this->sale = ($open ? SaleFactory::new() : SaleFactory::new()->closed())->create(['shop' => $shop]);
        $delivery = SaleDeliveryFactory::createOne(['sale' => $this->sale]);
        $this->bread = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::createOne(['shop' => $shop, 'name' => 'Pain de campagne', 'priceCents' => 500])]);
        $this->tomatoes = SaleOfferFactory::createOne(['sale' => $this->sale, 'product' => ProductFactory::new()->byWeight()->create(['shop' => $shop, 'name' => 'Tomates anciennes', 'priceCents' => 450])]);

        $this->jeanne = self::order($this->sale, $this->bread, 2, ['saleDelivery' => $delivery], CustomerFactory::createOne(['firstName' => 'Jeanne', 'lastName' => 'Martin']));
        $this->tomatoLine = OrderLineFactory::createOne(['order' => $this->jeanne, 'saleOffer' => $this->tomatoes, 'quantity' => 1500]);
        $this->paul = self::order($this->sale, $this->bread, 1, ['saleDelivery' => $delivery, 'status' => OrderStatus::Delivered], CustomerFactory::createOne(['firstName' => 'Paul', 'lastName' => 'Durand']));

        $client->loginUser($this->producer, 'producer');

        return $client;
    }
}
