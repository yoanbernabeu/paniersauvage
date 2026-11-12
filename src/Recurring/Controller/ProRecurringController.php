<?php

declare(strict_types=1);

namespace App\Recurring\Controller;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Recurring\Entity\RecurringOrder;
use App\Recurring\Entity\RecurringPlan;
use App\Recurring\Exception\RecurringOrderNotAllowedException;
use App\Recurring\Form\RecurringPlanType;
use App\Recurring\Repository\RecurringOrderRepository;
use App\Recurring\Repository\RecurringPlanRepository;
use App\Recurring\Service\RecurringOrderManager;
use App\Sale\Entity\SaleSchedule;
use App\Sale\Repository\SaleScheduleRepository;
use App\Shop\Service\CurrentShop;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Producer side of the recurring orders: settings of a recurrence's plan, subscribers and their
 * generated orders, suspension and end of a subscription. Everything is scoped to the current
 * shop: another producer's plan or subscriber is a 404.
 */
final class ProRecurringController extends AbstractController
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly RecurringPlanRepository $plans,
        private readonly RecurringOrderRepository $recurringOrders,
        private readonly RecurringOrderManager $manager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/pro/paniers', name: 'pro_recurring_index', methods: ['GET'])]
    public function index(SaleScheduleRepository $schedules): Response
    {
        $shop = $this->currentShop->get();
        $plans = $this->plans->findByShop($shop);

        $subscriptionsByPlan = [];
        foreach ($this->recurringOrders->findByShop($shop) as $recurringOrder) {
            $subscriptionsByPlan[$recurringOrder->getPlan()->getId()][] = $recurringOrder;
        }

        $withPlan = array_map(static fn (RecurringPlan $plan): int => $plan->getSchedule()->getId() ?? 0, $plans);

        return $this->render('recurring/pro/index.html.twig', [
            'plans' => $plans,
            'subscriptions_by_plan' => $subscriptionsByPlan,
            'schedules_without_plan' => array_values(array_filter($schedules->findByShop($shop), static fn (SaleSchedule $schedule): bool => !\in_array($schedule->getId(), $withPlan, true))),
        ]);
    }

    #[Route('/pro/recurrences/{id}/panier', name: 'pro_recurring_plan_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, SaleScheduleRepository $schedules, ProductRepository $productRepository): Response
    {
        $shop = $this->currentShop->get();
        $schedule = $schedules->findOneInShop($id, $shop) ?? throw $this->createNotFoundException();
        $plan = $this->plans->findOneBySchedule($schedule);
        $isNew = !$plan instanceof RecurringPlan;
        $plan ??= new RecurringPlan($schedule, $this->clock->now());

        // The products of the recurrence (its whole range when none is ticked), plus those already in the plan.
        $products = $schedule->getProducts()->isEmpty() ? $productRepository->findByShop($shop) : array_values(array_filter($schedule->getProducts()->toArray(), static fn (Product $product): bool => !$product->isArchived()));
        foreach ($plan->getItems() as $item) {
            if (!\in_array($item->getProduct(), $products, true)) {
                $products[] = $item->getProduct();
            }
        }

        $form = $this->createForm(RecurringPlanType::class, $plan, ['products' => $products]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($plan);
            $this->entityManager->flush();
            $this->addFlash('success', $plan->isEnabled()
                ? 'Ton panier est enregistré et ouvert aux inscriptions.'
                : 'Ton panier est enregistré. Coche « Ouvrir les inscriptions » quand tu es prêt.');

            return $this->redirectToRoute('pro_recurring_index', status: Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && !$isNew) {
            // Invalid values were written to the managed plan by the form: forget them.
            $this->entityManager->refresh($plan);
        }

        return $this->render('recurring/pro/plan_form.html.twig', [
            'form' => $form,
            'plan' => $plan,
            'schedule' => $schedule,
            'is_new' => $isNew,
            'products' => $products,
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    #[Route('/pro/paniers/abonnes/{id}', name: 'pro_recurring_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $recurringOrder = $this->find($id);

        return $this->render('recurring/pro/show.html.twig', [
            'recurring_order' => $recurringOrder,
            'plan' => $recurringOrder->getPlan(),
        ]);
    }

    #[Route('/pro/paniers/abonnes/{id}/suspendre', name: 'pro_recurring_order_suspend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function suspend(int $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, function (RecurringOrder $recurringOrder): string {
            $this->manager->suspend($recurringOrder);

            return 'L\'abonnement est suspendu : plus aucune commande ne sera créée. L\'abonné est prévenu par e-mail.';
        });
    }

    #[Route('/pro/paniers/abonnes/{id}/reprendre', name: 'pro_recurring_order_resume', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resume(int $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, function (RecurringOrder $recurringOrder): string {
            $this->manager->resumeSubscription($recurringOrder);

            return 'L\'abonnement reprend : une commande sera créée à chaque vente.';
        });
    }

    #[Route('/pro/paniers/abonnes/{id}/terminer', name: 'pro_recurring_order_end', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function end(int $id, Request $request): RedirectResponse
    {
        return $this->act($id, $request, function (RecurringOrder $recurringOrder): string {
            $this->manager->endByProducer($recurringOrder);

            return 'L\'abonnement est terminé. L\'abonné est prévenu par e-mail ; ses commandes déjà créées restent dans tes ventes.';
        });
    }

    /**
     * @param \Closure(RecurringOrder): string $action returns the success message
     */
    private function act(int $id, Request $request, \Closure $action): RedirectResponse
    {
        $recurringOrder = $this->find($id);

        if (!$this->isCsrfTokenValid('submit', $request->getPayload()->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->addFlash('success', $action($recurringOrder));
        } catch (RecurringOrderNotAllowedException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('pro_recurring_order_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function find(int $id): RecurringOrder
    {
        return $this->recurringOrders->findOneInShop($id, $this->currentShop->get()) ?? throw $this->createNotFoundException();
    }
}
