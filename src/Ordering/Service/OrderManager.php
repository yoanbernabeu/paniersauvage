<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderEventType;
use App\Ordering\Enum\OrderSource;
use App\Ordering\Event\OrderCancelled;
use App\Ordering\Event\OrderPlaced;
use App\Ordering\Event\OrderUpdated;
use App\Ordering\Exception\FirstOrderCapExceededException;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Model\CustomerIdentity;
use App\Ordering\Model\OrderRequest;
use App\Sale\Entity\Sale;
use App\Sale\Entity\SaleOffer;
use App\Shared\Enum\Actor;
use App\Shared\Service\Quantity;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Places, updates and cancels orders. Each operation is one database transaction: stock moves
 * (through ReservationService), order lines and history entry are committed together, then the
 * domain event is dispatched. No e-mail is sent from here (SPEC §6).
 *
 * Who may do what:
 * - a customer places an order while the sale accepts orders (during a last-minute sale, on its
 *   leftovers only), and edits or cancels it until the edit cutoff (`Order::isCustomerEditable()`);
 * - a producer may add, edit or cancel orders as long as the sale is neither done nor cancelled;
 * - the system (schedulers, sale cancellation) is not restricted.
 *
 * A new customer's order (placed or edited by the customer) stays within the shop's first-order cap
 * (NewCustomerPolicy); the producer is never capped.
 */
final readonly class OrderManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReservationService $reservations,
        private OrderReferenceGenerator $references,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private NewCustomerPolicy $newCustomers,
    ) {
    }

    /**
     * @throws InsufficientStockException     when a product is no longer available in that quantity
     * @throws OrderNotAllowedException       when the sale does not take this order
     * @throws FirstOrderCapExceededException when a new customer orders beyond the shop's cap
     */
    public function placeOrder(Sale $sale, Customer $customer, OrderRequest $request, Actor $actor, ?OrderSource $source = null): Order
    {
        $now = $this->clock->now();

        if (Actor::Customer === $actor && !$sale->acceptsOrders($now)) {
            throw new OrderNotAllowedException('Les commandes ne sont pas ouvertes pour cette vente.');
        }
        $this->assertSaleIsOngoing($sale, $actor);

        $quantities = $this->resolveQuantities($sale, $request);
        if ([] === $quantities) {
            throw new OrderNotAllowedException('Choisis au moins un produit.');
        }
        if (Actor::Customer === $actor) {
            // During a last-minute sale, customers order the leftovers put back on sale only.
            foreach ($quantities as [$offer]) {
                if (!\in_array($offer, $sale->getOrderableOffers(), true)) {
                    throw new OrderNotAllowedException(\sprintf('« %s » n\'est plus disponible.', $offer->getProduct()->getName()));
                }
            }
        }
        $this->assertDeliveryIsValid($sale, $request);
        if (Actor::Customer === $actor) {
            $this->newCustomers->assertWithinFirstOrderCap($sale->getShop(), $customer, $this->totalOf($quantities));
        }

        $order = $this->transactional(function () use ($sale, $customer, $request, $quantities, $actor, $now, $source): Order {
            $this->reservations->apply(array_map(static fn (array $entry): int => $entry[1], $quantities));

            $order = new Order($sale, $customer, $request->delivery, $this->references->generate(), $now);
            if ($request->identity instanceof CustomerIdentity) {
                $order->setCustomerIdentity($request->identity->firstName, $request->identity->lastName, $request->identity->phone);
            }
            $this->applyDeliveryAndNote($order, $request);
            $order->setSource($source);

            foreach ($quantities as [$offer, $quantity]) {
                new OrderLine($order, $offer, $quantity);
            }

            $order->recordEvent(OrderEventType::Placed, $actor, [
                'total_cents' => $order->getTotalCents(),
                'items' => $order->getItemCount(),
            ], $now);

            $this->entityManager->persist($order);

            return $order;
        });

        $this->eventDispatcher->dispatch(new OrderPlaced($order, $actor));

        return $order;
    }

    /**
     * Brings the order to the requested state. Existing lines keep the price, VAT rate and label
     * frozen when they were created. An order left without any item is cancelled. A request that
     * changes nothing records nothing and dispatches nothing.
     *
     * @throws InsufficientStockException     when an increase exceeds what is left
     * @throws OrderNotAllowedException       when the order can no longer be edited by this actor
     * @throws FirstOrderCapExceededException when a new customer raises the order beyond the shop's cap
     */
    public function updateOrder(Order $order, OrderRequest $request, Actor $actor): Order
    {
        $now = $this->clock->now();
        $sale = $order->getSale();
        $quantities = $this->resolveQuantities($sale, $request);

        if ([] === $quantities) {
            return $this->cancelOrder($order, $actor);
        }
        $this->assertDeliveryIsValid($sale, $request);
        // Lowering an order is always possible, even when the cap was set after it was placed.
        $total = $this->totalOf($quantities, $order);
        if (Actor::Customer === $actor && $total > $order->getTotalCents()) {
            $this->newCustomers->assertWithinFirstOrderCap($sale->getShop(), $order->getCustomer(), $total);
        }

        $changed = $this->transactional(function () use ($order, $request, $quantities, $actor, $now): bool {
            $this->lock($order);
            $this->assertCanChange($order, $actor, $now);

            $previousTotal = $order->getTotalCents();
            $deltas = [];
            $changes = [];

            foreach ($order->getLines() as $line) {
                $offerId = $this->idOf($line->getSaleOffer());
                $target = isset($quantities[$offerId]) ? $quantities[$offerId][1] : 0;
                $deltas[$offerId] = $target - $line->getQuantity();
            }
            foreach ($quantities as $offerId => [, $quantity]) {
                $deltas[$offerId] ??= $quantity;
            }

            // Nothing to save: no history entry, no e-mail.
            if ([] === array_filter($deltas) && !$this->deliveryOrNoteChanges($order, $request)) {
                return false;
            }

            $this->reservations->apply($deltas);

            foreach ($order->getLines()->toArray() as $line) {
                $offerId = $this->idOf($line->getSaleOffer());
                $target = isset($quantities[$offerId]) ? $quantities[$offerId][1] : 0;

                if ($target !== $line->getQuantity()) {
                    $changes[] = ['label' => $line->getLabel(), 'from' => $line->getQuantity(), 'to' => $target];
                }

                if (0 === $target) {
                    $order->removeLine($line);
                    $this->entityManager->remove($line);
                } else {
                    $line->setQuantity($target);
                }
                unset($quantities[$offerId]);
            }
            foreach ($quantities as [$offer, $quantity]) {
                $line = new OrderLine($order, $offer, $quantity);
                $changes[] = ['label' => $line->getLabel(), 'from' => 0, 'to' => $quantity];
            }

            $this->applyDeliveryAndNote($order, $request);
            $order->recomputeTotal();
            $order->touch($now);
            $order->recordEvent(OrderEventType::Updated, $actor, [
                'previous_total_cents' => $previousTotal,
                'total_cents' => $order->getTotalCents(),
                'changes' => $changes,
            ], $now);

            return true;
        });

        if ($changed) {
            $this->eventDispatcher->dispatch(new OrderUpdated($order, $actor));
        }

        return $order;
    }

    /**
     * Cancels the order and gives its stock back. Cancelling twice is harmless: the second call
     * does nothing and dispatches nothing.
     *
     * @throws OrderNotAllowedException when the order can no longer be cancelled by this actor
     */
    public function cancelOrder(Order $order, Actor $actor): Order
    {
        $now = $this->clock->now();

        $cancelled = $this->transactional(function () use ($order, $actor, $now): bool {
            $this->lock($order);

            if ($order->isCancelled()) {
                return false;
            }
            $this->assertCanChange($order, $actor, $now);

            $deltas = [];
            foreach ($order->getLines() as $line) {
                $deltas[$this->idOf($line->getSaleOffer())] = -$line->getQuantity();
            }
            $this->reservations->apply($deltas);

            $order->cancel();
            $order->touch($now);
            $order->recordEvent(OrderEventType::Cancelled, $actor, ['total_cents' => $order->getTotalCents()], $now);

            return true;
        });

        if ($cancelled) {
            $this->eventDispatcher->dispatch(new OrderCancelled($order, $actor));
        }

        return $order;
    }

    /**
     * Runs the callback in a transaction, flushing before the commit. Unlike
     * EntityManager::wrapInTransaction(), a business exception leaves the entity manager open so
     * the controller can show the form again.
     *
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function transactional(\Closure $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Locks the order row (`SELECT … FOR UPDATE`) and reloads the order and its lines, so that two
     * simultaneous edits or cancellations of the same order cannot both move the stock.
     */
    private function lock(Order $order): void
    {
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);

        foreach ($order->getLines() as $line) {
            $this->entityManager->refresh($line);
        }
    }

    private function assertCanChange(Order $order, Actor $actor, \DateTimeImmutable $now): void
    {
        if ($order->isCancelled()) {
            throw new OrderNotAllowedException('Cette commande est annulée.');
        }

        if (Actor::Customer === $actor && !$order->isCustomerEditable($now)) {
            throw new OrderNotAllowedException('Cette commande ne peut plus être modifiée en ligne.');
        }

        $this->assertSaleIsOngoing($order->getSale(), $actor);
    }

    private function assertSaleIsOngoing(Sale $sale, Actor $actor): void
    {
        if (Actor::System === $actor) {
            return;
        }

        if ($sale->isCancelled()) {
            throw new OrderNotAllowedException('Cette vente est annulée.');
        }

        if ($sale->isDone()) {
            throw new OrderNotAllowedException('Cette vente est terminée.');
        }
    }

    private function assertDeliveryIsValid(Sale $sale, OrderRequest $request): void
    {
        if ($request->delivery->getSale() !== $sale) {
            throw new OrderNotAllowedException('Ce point de livraison ne fait pas partie de cette vente.');
        }

        if ($request->delivery->isHome() && '' === trim($request->deliveryAddress ?? '')) {
            throw new OrderNotAllowedException('Indique ton adresse pour la livraison à domicile.');
        }
    }

    private function applyDeliveryAndNote(Order $order, OrderRequest $request): void
    {
        [$address, $note] = $this->addressAndNote($request);

        $order->setSaleDelivery($request->delivery);
        $order->setDeliveryAddress($address);
        $order->setNote($note);
    }

    private function deliveryOrNoteChanges(Order $order, OrderRequest $request): bool
    {
        return [$request->delivery, ...$this->addressAndNote($request)]
            !== [$order->getSaleDelivery(), $order->getDeliveryAddress(), $order->getNote()];
    }

    /**
     * @return array{?string, ?string} delivery address (home delivery only) and note, as saved on the order
     */
    private function addressAndNote(OrderRequest $request): array
    {
        $note = trim($request->note ?? '');
        $address = trim($request->deliveryAddress ?? '');

        return [
            $request->delivery->isHome() && '' !== $address ? $address : null,
            '' === $note ? null : $note,
        ];
    }

    /**
     * Keeps the positive quantities and resolves their offers within the sale.
     *
     * @return array<int, array{SaleOffer, int}> sale offer id => [offer, quantity in thousandths], ordered by id
     */
    private function resolveQuantities(Sale $sale, OrderRequest $request): array
    {
        $offers = [];
        foreach ($sale->getOffers() as $offer) {
            $offers[$this->idOf($offer)] = $offer;
        }

        $resolved = [];
        foreach ($request->quantities as $offerId => $quantity) {
            if ($quantity < 0) {
                throw new OrderNotAllowedException('Une quantité ne peut pas être négative.');
            }
            if (0 === $quantity) {
                continue;
            }

            $offer = $offers[$offerId] ?? throw new OrderNotAllowedException('Un des produits ne fait pas partie de cette vente.');
            if (!$offer->getProduct()->getUnit()->accepts($quantity)) {
                throw new OrderNotAllowedException(\sprintf('« %s » se vend à l\'unité : indique un nombre entier.', $offer->getProduct()->getName()));
            }
            $resolved[$offerId] = [$offer, $quantity];
        }
        ksort($resolved);

        return $resolved;
    }

    /**
     * What the order would cost: lines already in the order keep their frozen price.
     *
     * @param array<int, array{SaleOffer, int}> $quantities
     */
    private function totalOf(array $quantities, ?Order $order = null): int
    {
        $total = 0;
        foreach ($quantities as [$offer, $quantity]) {
            $unitPrice = $order?->getLineFor($offer)?->getUnitPriceCents() ?? $offer->getPriceCents();
            $total += Quantity::priceCents($quantity, $unitPrice);
        }

        return $total;
    }

    private function idOf(SaleOffer $offer): int
    {
        return $offer->getId() ?? throw new \LogicException('The sale offer must be persisted.');
    }
}
