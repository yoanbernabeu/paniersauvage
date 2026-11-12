<?php

declare(strict_types=1);

namespace App\Ordering\Service;

use App\Ordering\Entity\DeliverySheetAction;
use App\Ordering\Entity\Order;
use App\Ordering\Entity\OrderLine;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Enum\SheetActionOutcome;
use App\Ordering\Enum\SheetActionType;
use App\Ordering\Exception\SheetActionRejected;
use App\Ordering\Model\SheetAction;
use App\Ordering\Model\SheetActionResult;
use App\Ordering\Repository\DeliverySheetActionRepository;
use App\Shared\Service\FrenchFormatter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Applies the actions the delivery sheet queued while offline (SPEC §20, lot F), one at a time and
 * in the order the producer did them.
 *
 * - **Idempotent**: each action carries an identifier chosen by the browser. Under the lock of the
 *   order row, an identifier already processed gets its recorded outcome back and changes nothing,
 *   so a replay (lost answer, second tab) never adds a second entry to the order's history.
 * - **Conflicts**: each action carries the state the producer saw. It is applied only if the order
 *   is still in that state (or already in the requested one: nothing to do). An order cancelled or
 *   changed elsewhere in the meantime is a conflict: nothing is saved and the producer is told why.
 *
 * The changes themselves go through OrderDeliveryMarker and OrderWeighing, as from the forms.
 */
final readonly class DeliverySheetSync
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeliverySheetActionRepository $actions,
        private OrderDeliveryMarker $marker,
        private OrderWeighing $weighing,
        private FrenchFormatter $formatter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SheetActionRejected when the action can never be applied to this order
     */
    public function apply(Order $order, SheetAction $action): SheetActionResult
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // SELECT … FOR UPDATE: replays of the same action are served one after the other.
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            $processed = $this->actions->find($action->id);
            if ($processed instanceof DeliverySheetAction) {
                if ($processed->getOrder() !== $order || $processed->getType() !== $action->type) {
                    throw new SheetActionRejected('Cette action a déjà servi pour une autre commande.');
                }
                $connection->commit();

                return new SheetActionResult($processed->getOutcome(), $processed->getMessage(), replayed: true);
            }

            $this->entityManager->refresh($order->getSale());
            foreach ($order->getLines() as $line) {
                $this->entityManager->refresh($line);
            }

            $result = SheetActionType::Mark === $action->type ? $this->mark($order, $action) : $this->weigh($order, $action);

            $this->entityManager->persist(new DeliverySheetAction($action->id, $order, $action->type, $result->outcome, $result->message, $this->clock->now()));
            $this->entityManager->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function mark(Order $order, SheetAction $action): SheetActionResult
    {
        $status = $action->status ?? throw new SheetActionRejected('Indique le nouveau statut de la commande.');
        if (OrderStatus::Cancelled === $status) {
            throw new SheetActionRejected('Une commande ne s\'annule pas depuis la feuille de livraison.');
        }

        $name = $order->getCustomerDisplayName();
        if ($order->isCancelled() || $order->getSale()->isCancelled()) {
            return $this->conflict(\sprintf('La commande de %s a été annulée entre-temps : ne la remets pas.', $name));
        }
        $sale = $order->getSale();
        if (!$sale->isClosed() && !$sale->isDone()) {
            return $this->conflict(\sprintf('Les commandes de cette vente ne sont pas encore closes : la commande de %s n\'est pas cochée.', $name));
        }

        $current = $order->getStatus();
        if ($current === $status) {
            return new SheetActionResult(SheetActionOutcome::Unchanged);
        }
        if ($current !== $action->expected) {
            return $this->conflict(\sprintf('La commande de %s a changé entre-temps (%s) : vérifie-la avant de la cocher.', $name, lcfirst($current->label())));
        }

        $this->marker->mark($order, $status);

        return new SheetActionResult(SheetActionOutcome::Applied);
    }

    private function weigh(Order $order, SheetAction $action): SheetActionResult
    {
        $lines = [];
        foreach ($action->weights as $lineId => $weight) {
            $line = $order->getLines()->findFirst(static fn (int $key, OrderLine $line): bool => $line->getId() === $lineId)
                ?? throw new SheetActionRejected('Ce produit ne fait pas partie de la commande.');
            if (!$line->isWeighed()) {
                throw new SheetActionRejected(\sprintf('« %s » ne se vend pas au poids.', $line->getLabel()));
            }
            if (null !== $weight && ($weight <= 0 || $weight > OrderWeighing::MAX_WEIGHT)) {
                throw new SheetActionRejected(\sprintf('Indique un poids entre 0,001 et %d kg pour « %s ».', intdiv(OrderWeighing::MAX_WEIGHT, 1000), $line->getLabel()));
            }
            $lines[$lineId] = $line;
        }

        $name = $order->getCustomerDisplayName();
        if ($order->isCancelled() || $order->getSale()->isCancelled()) {
            return $this->conflict(\sprintf('La commande de %s a été annulée entre-temps : son poids n\'est pas enregistré.', $name));
        }

        $changes = [];
        foreach ($action->weights as $lineId => $weight) {
            $line = $lines[$lineId];
            $current = $line->getActualQuantity();
            if ($current === $weight) {
                continue;
            }
            if (\array_key_exists($lineId, $action->expectedWeights) && $current !== $action->expectedWeights[$lineId]) {
                $seen = null === $current ? 'effacé' : $this->formatter->quantity($current, $line->getUnit());

                return $this->conflict(\sprintf('Le poids de « %s » (commande de %s) a changé entre-temps (%s) : vérifie-le.', $line->getLabel(), $name, $seen));
            }
            $changes[$lineId] = $weight;
        }

        if ([] === $changes) {
            return new SheetActionResult(SheetActionOutcome::Unchanged);
        }

        $this->weighing->record($order, $changes);

        return new SheetActionResult(SheetActionOutcome::Applied);
    }

    private function conflict(string $message): SheetActionResult
    {
        return new SheetActionResult(SheetActionOutcome::Conflict, $message);
    }
}
