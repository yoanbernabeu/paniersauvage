<?php

declare(strict_types=1);

namespace App\Ordering\Controller;

use App\Customer\Service\OfflineCustomerManager;
use App\Ordering\Entity\Order;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Form\OrderFormData;
use App\Ordering\Form\OrderFormType;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderCustomerResolver;
use App\Ordering\Service\OrderManager;
use App\Ordering\Service\OrderWeighing;
use App\Sale\Entity\Sale;
use App\Sale\Repository\SaleRepository;
use App\Shared\Enum\Actor;
use App\Shared\Navigation\SafeRouter;
use App\Shop\Service\CurrentShop;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Producer side: add an order for a customer (e.g. taken on the phone), edit or cancel one.
 * Without e-mail address, the order goes to a customer without e-mail of the current shop
 * (OfflineCustomerManager): nobody else sees them and no e-mail is ever sent to them.
 * Allowed after the deadline too, as long as the sale is neither done nor cancelled.
 * Everything starts from the current shop: another producer's sale or order answers 404.
 */
#[Route('/pro/ventes/{id}/commandes', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_PRODUCER')]
final class ProOrderController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly SaleRepository $sales,
        private readonly OrderRepository $orders,
        private readonly OrderManager $orderManager,
        private readonly SafeRouter $safeRouter,
    ) {
    }

    #[Route('/nouvelle', name: 'pro_sale_order_new', methods: ['GET', 'POST'])]
    public function new(int $id, Request $request, OrderCustomerResolver $customerResolver, OfflineCustomerManager $offlineCustomers): Response
    {
        $sale = $this->findSale($id);
        $backUrl = $this->backUrl($sale);

        if ($sale->isDone() || $sale->isCancelled()) {
            $this->addFlash('error', $sale->isDone() ? 'Cette vente est terminée : tu ne peux plus y ajouter de commande.' : 'Cette vente est annulée : tu ne peux plus y ajouter de commande.');

            return $this->redirect($backUrl);
        }

        $data = OrderFormData::forSale($sale);
        $formOptions = ['sale' => $sale, 'identity' => true, 'email' => true, 'email_optional' => true, 'selling_steps' => false];
        $form = $this->createForm(OrderFormType::class, $data, $formOptions);
        $form->handleRequest($request);
        $stockMessages = [];

        if ($form->isSubmitted() && $form->isValid()) {
            $customer = $data->isEmailGiven()
                ? $customerResolver->resolve($data->email ?? '')
                : $offlineCustomers->resolve($sale->getShop(), $data->firstName, $data->lastName, $data->phone);

            try {
                $order = $this->orderManager->placeOrder($sale, $customer, $data->toRequest(), Actor::Producer);
                $this->addFlash('success', $customer->isWithoutEmail()
                    ? \sprintf('Commande ajoutée pour %s, sans e-mail : préviens-le toi-même des changements.', $order->getCustomerDisplayName())
                    : \sprintf('Commande ajoutée pour %s.', $order->getCustomerDisplayName()));

                return $this->redirect($backUrl, Response::HTTP_SEE_OTHER);
            } catch (InsufficientStockException $exception) {
                $stockMessages = $data->applyShortages($exception);
                $form = $this->createForm(OrderFormType::class, $data, $formOptions);
            } catch (OrderNotAllowedException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirect($backUrl);
            }
        }

        return $this->render('ordering/pro/form.html.twig', [
            'sale' => $sale,
            'order' => null,
            'form' => $form,
            'data' => $data,
            'back_url' => $backUrl,
            'stock_messages' => $stockMessages,
        ], [] === $stockMessages ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{orderId}/modifier', name: 'pro_order_edit', requirements: ['orderId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, int $orderId, Request $request, OrderWeighing $weighing): Response
    {
        $sale = $this->findSale($id);
        $order = $this->findOrder($orderId, $sale);
        $backUrl = $this->backUrl($sale);

        $refusal = $this->refusalFor($order);
        if (null !== $refusal) {
            $this->addFlash('error', $refusal);

            return $this->redirect($backUrl);
        }

        $data = OrderFormData::fromOrder($order);
        $formOptions = ['sale' => $sale, 'require_items' => false, 'selling_steps' => false, 'weights' => $order];
        $form = $this->createForm(OrderFormType::class, $data, $formOptions);
        $form->handleRequest($request);
        $stockMessages = [];

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->orderManager->updateOrder($order, $data->toRequest(), Actor::Producer);
                if (!$order->isCancelled()) {
                    $weighing->record($order, $data->weightsFor($order));
                }
                $name = $order->getCustomerDisplayName();
                $this->addFlash('success', $order->isCancelled() ? \sprintf('Commande de %s annulée.', $name) : \sprintf('Commande de %s modifiée.', $name));

                return $this->redirect($backUrl, Response::HTTP_SEE_OTHER);
            } catch (InsufficientStockException $exception) {
                $stockMessages = $data->applyShortages($exception, $order);
                $form = $this->createForm(OrderFormType::class, $data, $formOptions);
            } catch (OrderNotAllowedException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirect($backUrl);
            }
        }

        return $this->render('ordering/pro/form.html.twig', [
            'sale' => $sale,
            'order' => $order,
            'form' => $form,
            'data' => $data,
            'back_url' => $backUrl,
            'stock_messages' => $stockMessages,
        ], [] === $stockMessages ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/{orderId}/annuler', name: 'pro_order_cancel', requirements: ['orderId' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, int $orderId, Request $request): RedirectResponse
    {
        $sale = $this->findSale($id);
        $order = $this->findOrder($orderId, $sale);

        if (!$this->isCsrfTokenValid('pro_order_cancel_'.$orderId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->orderManager->cancelOrder($order, Actor::Producer);
            $this->addFlash('success', \sprintf('Commande de %s annulée.', $order->getCustomerDisplayName()));
        } catch (OrderNotAllowedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirect($this->backUrl($sale), Response::HTTP_SEE_OTHER);
    }

    private function findSale(int $id): Sale
    {
        $sale = $this->sales->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException('Unknown sale.');
        $this->sales->loadOffersAndDeliveries($sale);

        return $sale;
    }

    private function findOrder(int $orderId, Sale $sale): Order
    {
        return $this->orders->findOneInSale($orderId, $sale) ?? throw $this->createNotFoundException('Unknown order.');
    }

    private function refusalFor(Order $order): ?string
    {
        return match (true) {
            $order->isCancelled() => 'Cette commande est annulée : elle ne peut plus être modifiée.',
            $order->getSale()->isDone() => 'Cette vente est terminée : ses commandes ne sont plus modifiables.',
            $order->getSale()->isCancelled() => 'Cette vente est annulée : ses commandes ne sont plus modifiables.',
            default => null,
        };
    }

    private function backUrl(Sale $sale): string
    {
        return $this->safeRouter->path('pro_sale_show', ['id' => $sale->getId()]) ?? \sprintf('/pro/ventes/%d', $sale->getId());
    }
}
