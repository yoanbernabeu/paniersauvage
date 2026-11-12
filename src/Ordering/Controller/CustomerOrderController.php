<?php

declare(strict_types=1);

namespace App\Ordering\Controller;

use App\Customer\Entity\Customer;
use App\Ordering\Entity\Order;
use App\Ordering\Exception\FirstOrderCapExceededException;
use App\Ordering\Exception\InsufficientStockException;
use App\Ordering\Exception\OrderNotAllowedException;
use App\Ordering\Form\OrderFormData;
use App\Ordering\Form\OrderFormType;
use App\Ordering\Repository\OrderRepository;
use App\Ordering\Service\OrderIcsBuilder;
use App\Ordering\Service\OrderManager;
use App\Sale\Repository\SaleRepository;
use App\Shared\Enum\Actor;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A logged-in customer's own orders: view, edit, cancel, calendar file.
 * Someone else's order answers 404.
 */
#[Route('/mes-commandes/{reference}', requirements: ['reference' => '[A-Za-z0-9-]{1,20}'])]
#[IsGranted(Customer::ROLE_CUSTOMER)]
final class CustomerOrderController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderManager $orderManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'customer_order_show', methods: ['GET'])]
    public function show(string $reference, #[CurrentUser] Customer $customer): Response
    {
        $order = $this->findOwnOrder($reference, $customer);

        return $this->render('ordering/customer/show.html.twig', [
            'order' => $order,
            'sale' => $order->getSale(),
            'shop' => $order->getShop(),
            'editable' => $order->isCustomerEditable($this->clock->now()),
        ]);
    }

    #[Route('/modifier', name: 'customer_order_edit', methods: ['GET', 'POST'])]
    public function edit(string $reference, Request $request, #[CurrentUser] Customer $customer, SaleRepository $sales): Response
    {
        $order = $this->findOwnOrder($reference, $customer);

        if (!$order->isCustomerEditable($this->clock->now())) {
            $this->addFlash('info', 'Cette commande ne peut plus être modifiée en ligne. Contacte directement ton producteur.');

            return $this->redirectToRoute('customer_order_show', ['reference' => $order->getReference()]);
        }

        $sale = $order->getSale();
        $sales->loadOffersAndDeliveries($sale);
        $data = OrderFormData::fromOrder($order);
        $formOptions = ['sale' => $sale, 'require_items' => false, 'age_check' => true];
        $form = $this->createForm(OrderFormType::class, $data, $formOptions);
        $form->handleRequest($request);
        $stockMessages = [];
        $capMessage = null;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->orderManager->updateOrder($order, $data->toRequest(), Actor::Customer);
                $this->addFlash('success', $order->isCancelled() ? 'Ta commande est annulée.' : 'C\'est modifié ! Ta commande est à jour.');

                return $this->redirectToRoute('customer_order_show', ['reference' => $order->getReference()], Response::HTTP_SEE_OTHER);
            } catch (InsufficientStockException $exception) {
                $stockMessages = $data->applyShortages($exception, $order);
                $form = $this->createForm(OrderFormType::class, $data, $formOptions);
            } catch (FirstOrderCapExceededException $exception) {
                $capMessage = $exception->getMessage();
            } catch (OrderNotAllowedException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('customer_order_show', ['reference' => $order->getReference()]);
            }
        }

        return $this->render('ordering/customer/edit.html.twig', [
            'order' => $order,
            'sale' => $sale,
            'shop' => $order->getShop(),
            'form' => $form,
            'data' => $data,
            'stock_messages' => $stockMessages,
            'cap_message' => $capMessage,
        ], [] === $stockMessages && null === $capMessage ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/annuler', name: 'customer_order_cancel', methods: ['POST'])]
    public function cancel(string $reference, Request $request, #[CurrentUser] Customer $customer): RedirectResponse
    {
        $order = $this->findOwnOrder($reference, $customer);

        if (!$this->isCsrfTokenValid('customer_order_cancel_'.$order->getReference(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->orderManager->cancelOrder($order, Actor::Customer);
            $this->addFlash('success', 'Ta commande est annulée.');
        } catch (OrderNotAllowedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('customer_order_show', ['reference' => $order->getReference()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/calendrier.ics', name: 'customer_order_ics', methods: ['GET'])]
    public function ics(string $reference, OrderIcsBuilder $icsBuilder, #[CurrentUser] Customer $customer): Response
    {
        $order = $this->findOwnOrder($reference, $customer);

        return IcsResponse::create($icsBuilder->build($order), $order);
    }

    private function findOwnOrder(string $reference, Customer $customer): Order
    {
        $order = $this->orders->findOneByReference($reference);

        if (!$order instanceof Order || $order->getCustomer()->getId() !== $customer->getId()) {
            throw $this->createNotFoundException('Unknown order.');
        }

        return $order;
    }
}
