<?php

declare(strict_types=1);

namespace App\Sale\Controller;

use App\Ordering\Entity\Order;
use App\Ordering\Enum\OrderStatus;
use App\Ordering\Exception\DeliveryMarkNotAllowedException;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Exception\SheetActionRejected;
use App\Ordering\Model\SheetActionPayload;
use App\Ordering\Service\DeliverySheetSync;
use App\Ordering\Service\OrderDeliveryMarker;
use App\Ordering\Service\OrderSummarizer;
use App\Ordering\Service\OrderWeighing;
use App\Ordering\Service\SaleOrders;
use App\Sale\Repository\SaleRepository;
use App\Shared\Service\FrenchFormatter;
use App\Shared\Service\Quantity;
use App\Shop\Service\CurrentShop;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The screen the producer uses on the phone while delivering: tick each order as delivered and
 * enter the actual weight of the products sold by weight. Nothing about payment is shown or
 * recorded (SPEC §1): only the amount the customer will settle.
 *
 * Without JavaScript, every tick and weight is a form posted to `pro_order_mark` / `pro_order_weigh`.
 * With JavaScript, the sheet works offline (SPEC §20, lot F): actions are applied on screen at once,
 * queued in the browser and replayed in order to `pro_order_sheet_action` when the network is back;
 * a service worker (`pro_delivery_sheet_worker`) keeps the sheet and its assets to reopen it offline.
 */
final class DeliverySheetController extends AbstractController
{
    /** CSRF token id of the delivery checkboxes (stateless, see config/packages/csrf.yaml). */
    public const string MARK_CSRF_ID = 'order-mark';

    /** Header carrying the CSRF token of the queued actions (the value of `csrf_token('order-mark')`). */
    public const string CSRF_HEADER = 'X-Csrf-Token';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleRepository $sales,
        private readonly SaleOrders $saleOrders,
        private readonly OrderDeliveryMarker $marker,
        private readonly OrderSummarizer $summarizer,
        private readonly OrderWeighing $weighing,
        private readonly DeliverySheetSync $sync,
        private readonly FrenchFormatter $formatter,
    ) {
    }

    #[Route('/pro/ventes/{id}/livraison', name: 'pro_sale_delivery_sheet', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sheet(int $id, Request $request): Response
    {
        $sale = $this->sales->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();

        // A sale can have several deliveries: the sheet shows one at a time (?creneau=<id>).
        $deliveries = array_values($sale->getDeliveries()->toArray());
        $requested = $request->query->getInt('creneau');
        $current = array_find($deliveries, fn ($delivery): bool => 0 === $requested || $delivery->getId() === $requested);
        if (0 !== $requested && null === $current) {
            throw $this->createNotFoundException();
        }

        $orders = null === $current ? [] : $this->saleOrders->forDelivery($current, $this->saleOrders->forSale($sale));
        $sheet = $this->summarizer->deliverySheet($orders);

        return $this->render('sale/delivery_sheet.html.twig', [
            'sale' => $sale,
            'deliveries' => $deliveries,
            'current' => $current,
            'explicit_delivery' => 0 !== $requested,
            'todo' => $sheet->todo,
            'done' => $sheet->done,
            'truck' => $sheet->truck,
        ]);
    }

    #[Route('/pro/commandes/{id}/statut', name: 'pro_order_mark', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function mark(int $id, Request $request): RedirectResponse
    {
        $order = $this->saleOrders->findInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid(self::MARK_CSRF_ID, $payload->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = OrderStatus::tryFrom($payload->getString('status'));
        if (!\in_array($status, [OrderStatus::Delivered, OrderStatus::NotDelivered, OrderStatus::Confirmed], true)) {
            throw $this->createNotFoundException('Unknown delivery status.');
        }

        try {
            $this->marker->mark($order, $status);
        } catch (DeliveryMarkNotAllowedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->backToSheet($order, $payload->getBoolean('explicit_delivery'));
    }

    /**
     * Actual weights of an order's products sold by weight: `weights[<line id>]` in kilograms
     * ("1,25"); an empty field clears the weight.
     */
    #[Route('/pro/commandes/{id}/poids', name: 'pro_order_weigh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function weigh(int $id, Request $request): RedirectResponse
    {
        $order = $this->saleOrders->findInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();
        $payload = $request->getPayload();

        if (!$this->isCsrfTokenValid(self::MARK_CSRF_ID, $payload->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $weights = [];
        $typed = $payload->all('weights');
        foreach ($typed as $lineId => $value) {
            $value = \is_string($value) ? trim($value) : '';
            $weight = '' === $value ? null : Quantity::parse($value);
            if ('' !== $value && null === $weight) {
                $this->addFlash('error', 'Indique un poids en kilos, par exemple 1,25.');

                return $this->backToSheet($order, $payload->getBoolean('explicit_delivery'));
            }
            $weights[(int) $lineId] = $weight;
        }

        try {
            if ($this->weighing->record($order, $weights)) {
                $this->addFlash('success', \sprintf('Poids enregistré pour %s.', $order->getCustomerDisplayName()));
            }
        } catch (OrderNotAllowedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->backToSheet($order, $payload->getBoolean('explicit_delivery'));
    }

    /**
     * One action queued by the sheet (SheetActionPayload), replayed as many times as needed: the
     * answer is always the outcome and the order as the server now sees it. 404 for an order of
     * another shop, 422 for an action that can never be applied (the browser drops it).
     */
    #[Route('/pro/commandes/{id}/action', name: 'pro_order_sheet_action', requirements: ['id' => '\d+'], methods: ['POST'], format: 'json')]
    public function action(int $id, Request $request, #[MapRequestPayload] SheetActionPayload $payload): JsonResponse
    {
        $order = $this->saleOrders->findInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid(self::MARK_CSRF_ID, (string) $request->headers->get(self::CSRF_HEADER))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $result = $this->sync->apply($order, $payload->toAction());
        } catch (\InvalidArgumentException|SheetActionRejected $exception) {
            return $this->json(['outcome' => 'rejected', 'message' => $exception instanceof SheetActionRejected ? $exception->getMessage() : 'Cette action est incomplète.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'outcome' => $result->outcome->value,
            'replayed' => $result->replayed,
            'message' => $result->message,
            'order' => $this->orderState($order),
        ]);
    }

    /**
     * The service worker of the delivery sheet. Served under /pro/ so that its scope covers the
     * sheets (and sees the logout, to forget them); it only answers for the sheets and their assets.
     */
    #[Route('/pro/feuille-hors-ligne.js', name: 'pro_delivery_sheet_worker', methods: ['GET'])]
    public function worker(): Response
    {
        $response = $this->render('sale/delivery_sheet_worker.js.twig', [], new Response(headers: ['Content-Type' => 'text/javascript; charset=UTF-8']));
        $response->headers->set('Cache-Control', 'no-cache, private');

        return $response;
    }

    /**
     * @return array{id: int|null, status: string, total: string, estimated: bool, weights: array<int, int|null>}
     */
    private function orderState(Order $order): array
    {
        $weights = [];
        foreach ($order->getLines() as $line) {
            if ($line->isWeighed() && null !== $line->getId()) {
                $weights[$line->getId()] = $line->getActualQuantity();
            }
        }

        return [
            'id' => $order->getId(),
            'status' => $order->getStatus()->value,
            'total' => $this->formatter->money($order->getTotalCents()),
            'estimated' => $order->isEstimated(),
            'weights' => $weights,
        ];
    }

    /**
     * Back to the very same sheet URL, so Turbo refreshes the page in place (morphing).
     */
    private function backToSheet(Order $order, bool $explicitDelivery): RedirectResponse
    {
        $parameters = ['id' => $order->getSale()->getId()];
        if ($explicitDelivery) {
            $parameters['creneau'] = $order->getSaleDelivery()->getId();
        }

        return $this->redirectToRoute('pro_sale_delivery_sheet', $parameters, Response::HTTP_SEE_OTHER);
    }
}
