<?php

declare(strict_types=1);

namespace App\Customer\Controller;

use App\Customer\Entity\Customer;
use App\Customer\Form\AccountDeletionType;
use App\Customer\Form\ProfileType;
use App\Customer\Service\CustomerEraser;
use App\Customer\Service\FollowManager;
use App\Ordering\Entity\Order;
use App\Ordering\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mon compte": the customer's orders (all producers), followed producers, profile and account deletion.
 */
#[IsGranted(Customer::ROLE_CUSTOMER)]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/mon-compte', name: 'customer_account', methods: ['GET', 'POST'])]
    public function account(Request $request, #[CurrentUser] Customer $customer, FollowManager $followManager, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProfileType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'C\'est enregistré.');

            return $this->redirectToRoute('customer_account');
        }

        if ($form->isSubmitted()) {
            // Never keep rejected values on the entity the session token points to.
            $entityManager->refresh($customer);
        }

        [$upcoming, $past] = $this->splitOrders($customer);

        return $this->render('customer/account/index.html.twig', [
            'customer' => $customer,
            'upcoming_orders' => $upcoming,
            'past_orders' => $past,
            'followers' => $followManager->findByCustomer($customer),
            'form' => $form,
        ]);
    }

    #[Route('/mon-compte/supprimer', name: 'customer_account_delete', methods: ['GET', 'POST'])]
    public function delete(Request $request, #[CurrentUser] Customer $customer, CustomerEraser $eraser, Security $security): Response
    {
        $pendingOrders = $eraser->findPendingOrders($customer);
        if ([] !== $pendingOrders) {
            return $this->render('customer/account/delete_blocked.html.twig', [
                'pending_orders' => $pendingOrders,
            ], new Response(status: $request->isMethod('POST') ? Response::HTTP_CONFLICT : Response::HTTP_OK));
        }

        $form = $this->createForm(AccountDeletionType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $eraser->erase($customer);
            $security->logout(false);
            $this->addFlash('success', 'Ton compte est supprimé. Merci d\'avoir soutenu tes producteurs, à bientôt 🌾');

            return $this->redirectToRoute('customer_login');
        }

        return $this->render('customer/account/delete.html.twig', [
            'form' => $form,
            'has_orders' => $this->orders->count(['customer' => $customer]) > 0,
        ]);
    }

    /**
     * @return array{list<Order>, list<Order>} orders still to be delivered (soonest first), then all the others (latest first)
     */
    private function splitOrders(Customer $customer): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        $upcoming = [];
        $past = [];

        foreach ($this->orders->findByCustomer($customer) as $order) {
            $sale = $order->getSale();

            if ($order->isConfirmed() && $sale->getStatus()->isOngoing() && $sale->getDeliveryDate() >= $today) {
                $upcoming[] = $order;
            } else {
                $past[] = $order;
            }
        }

        usort($upcoming, static fn (Order $a, Order $b): int => $a->getSaleDelivery()->getStartsAt() <=> $b->getSaleDelivery()->getStartsAt());

        return [$upcoming, $past];
    }
}
